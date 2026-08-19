<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Process\Process;

class AccClassificationImportService
{
    private const DATA_START_ROW = 4;
    private const COL_BANK_CLIENT_ID = 3;
    private const COL_FULL_NAME = 6;
    private const COL_MAX_OVERDUE_DAYS = 16;

    public function __construct(private ClientClassificationService $classificationService)
    {
    }

    /**
     * Reads the encrypted ACC report and returns which classifications
     * are present in it, without changing anything in the database.
     */
    public function preview(string $encryptedPath, string $password): array
    {
        $decryptedPath = $this->decryptToTempFile($encryptedPath, $password);

        $nameIndex = $this->buildClientNameIndex();

        $classificationCounts = [];
        $rows = [];
        $matchedCount = 0;
        $unmatched = [];

        try {
            foreach ($this->parseRows($decryptedPath) as [$bankClientId, $fullName, $maxOverdueDays]) {
                $classification = $this->classificationService->classificationByOverdue($maxOverdueDays);
                $classificationCounts[$classification->name] = ($classificationCounts[$classification->name] ?? 0) + 1;

                $match = $this->resolveClientMatch($fullName, $nameIndex);
                if ($match['status'] === 'matched') {
                    $matchedCount++;
                } else {
                    $unmatched[] = $fullName;
                }

                $rows[] = [
                    'bank_client_id' => $bankClientId,
                    'full_name' => $fullName,
                    'classification' => $classification->name,
                    'matched' => $match['status'] === 'matched',
                    'match_status' => $match['status'], // matched | unmatched | ambiguous
                ];
            }
        } finally {
            if (file_exists($decryptedPath)) {
                @unlink($decryptedPath);
            }
        }

        $classifications = [];
        foreach ($classificationCounts as $name => $count) {
            $classifications[] = ['name' => $name, 'count' => $count];
        }

        return [
            'classifications' => $classifications,
            'rows' => $rows,
            //'matched_count' => $matchedCount,
            //'unmatched_count' => count($unmatched),
            //'unmatched' => $unmatched,
        ];
    }

    /**
     * Applies the classifications from the encrypted ACC report to matching
     * clients — a client is only ever moved to a worse classification, never better.
     *
     * Clients are matched by normalized full name (name+surname, either order),
     * not bank_client_id — that column isn't populated on our Client records.
     * A name that matches more than one client is treated as ambiguous and
     * skipped, rather than guessing which one is meant.
     */
    public function apply(string $encryptedPath, string $password, string $sourceLabel = ''): array
    {
        $decryptedPath = $this->decryptToTempFile($encryptedPath, $password);

        $nameIndex = $this->buildClientNameIndex();

        $summary = [
            'updated' => [],
            'skipped' => [],
            'unmatched' => [],
            'ambiguous' => [],
            'errors' => [],
        ];

        try {
            foreach ($this->parseRows($decryptedPath) as [$bankClientId, $fullName, $maxOverdueDays]) {
                try {
                    $match = $this->resolveClientMatch($fullName, $nameIndex);

                    if ($match['status'] === 'unmatched') {
                        $summary['unmatched'][] = $fullName;
                        continue;
                    }
                    if ($match['status'] === 'ambiguous') {
                        $summary['ambiguous'][] = $fullName;
                        continue;
                    }

                    $client = Client::with('classification')->find($match['client_id']);
                    if (!$client) {
                        $summary['unmatched'][] = $fullName;
                        continue;
                    }

                    $classification = $this->classificationService->classificationByOverdue($maxOverdueDays);
                    $comment = 'Client classification update from ACC periodic report'
                        . ($sourceLabel !== '' ? " ({$sourceLabel})" : '');

                    $applied = $this->classificationService->applyClassificationIfWorse($client, $classification, $comment);

                    $entry = [
                        'client_id' => $client->id,
                        'full_name' => $fullName,
                        'max_overdue_days' => $maxOverdueDays,
                        'classification' => $classification->name,
                    ];

                    $summary[$applied ? 'updated' : 'skipped'][] = $entry;
                } catch (\Throwable $e) {
                    Log::error("ACC import: failed processing \"{$fullName}\": " . $e->getMessage());
                    $summary['errors'][] = ['full_name' => $fullName, 'error' => $e->getMessage()];
                }
            }
        } finally {
            if (file_exists($decryptedPath)) {
                @unlink($decryptedPath);
            }
        }

        return $summary;
    }

    /**
     * Maps every normalized client-name variant to the client id(s) that
     * produce it, so each ACC row can be resolved with a single array lookup
     * instead of a query per row. A name shared by more than one client ends
     * up with 2+ ids under the same key, which resolveClientMatch() below
     * reads as "ambiguous" rather than picking one arbitrarily.
     */
    private function buildClientNameIndex(): array
    {
        $index = [];

        Client::query()
            ->whereNull('deleted_at')
            ->select(['id', 'type', 'name', 'surname', 'company_name'])
            ->chunk(1000, function ($clients) use (&$index) {
                foreach ($clients as $client) {
                    foreach ($this->nameKeysFor($client) as $key) {
                        $index[$key][] = $client->id;
                    }
                }
            });

        return $index;
    }

    private function nameKeysFor(Client $client): array
    {
        if ($client->type === 'legal') {
            $key = $this->normalizeName((string) $client->company_name);

            return $key !== '' ? [$key] : [];
        }

        $name    = trim((string) $client->name);
        $surname = trim((string) $client->surname);
        if ($name === '' || $surname === '') {
            return [];
        }

        // The ACC report's name order isn't guaranteed, so index both.
        return array_unique([
            $this->normalizeName($name . ' ' . $surname),
            $this->normalizeName($surname . ' ' . $name),
        ]);
    }

    /**
     * @return array{status: 'matched'|'unmatched'|'ambiguous', client_id: int|null}
     */
    private function resolveClientMatch(string $fullName, array $nameIndex): array
    {
        $key = $this->normalizeName($fullName);
        if ($key === '') {
            return ['status' => 'unmatched', 'client_id' => null];
        }

        $ids = array_unique($nameIndex[$key] ?? []);

        if (count($ids) === 1) {
            return ['status' => 'matched', 'client_id' => (int) reset($ids)];
        }
        if (count($ids) > 1) {
            return ['status' => 'ambiguous', 'client_id' => null];
        }

        return ['status' => 'unmatched', 'client_id' => null];
    }

    private function normalizeName(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_strtolower($value, 'UTF-8');
    }

    private function decryptToTempFile(string $encryptedPath, string $password): string
    {
        $tempDir = storage_path('app/tmp/acc-import');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $decryptedPath = $tempDir . '/' . (string) Str::uuid() . '.xlsx';

        $process = new Process(['msoffcrypto-tool', '-p', $password, $encryptedPath, $decryptedPath]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            if ($process->getExitCode() === 127 || stripos($error, 'not found') !== false || stripos($error, 'not recognized') !== false) {
                throw new \RuntimeException(
                    'Decrypting ACC files requires the "msoffcrypto-tool" command to be installed on this server '
                    . '(pip install msoffcrypto-tool).'
                );
            }

            throw new \RuntimeException('Failed to decrypt the ACC file — wrong password or corrupt file. ' . $error);
        }

        if (!file_exists($decryptedPath) || filesize($decryptedPath) === 0) {
            throw new \RuntimeException('Decryption produced no output — wrong password or corrupt file.');
        }

        return $decryptedPath;
    }

    private function parseRows(string $decryptedPath): \Generator
    {
        $spreadsheet = IOFactory::load($decryptedPath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();

        for ($row = self::DATA_START_ROW; $row <= $highestRow; $row++) {
            $bankClientId = trim((string) $sheet->getCellByColumnAndRow(self::COL_BANK_CLIENT_ID, $row)->getValue());
            $fullName = trim((string) $sheet->getCellByColumnAndRow(self::COL_FULL_NAME, $row)->getValue());
            $maxOverdueRaw = $sheet->getCellByColumnAndRow(self::COL_MAX_OVERDUE_DAYS, $row)->getValue();

            if ($fullName === '' || !is_numeric($maxOverdueRaw)) {
                continue;
            }

            yield [$bankClientId, $fullName, (int) $maxOverdueRaw];
        }

        $spreadsheet->disconnectWorksheets();
    }
}
