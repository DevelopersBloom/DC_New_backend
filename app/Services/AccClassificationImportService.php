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

        $classificationCounts = [];
        $rows = [];
        $matchedCount = 0;
        $unmatched = [];

        try {
            foreach ($this->parseRows($decryptedPath) as [$bankClientId, $fullName, $maxOverdueDays]) {
                $classification = $this->classificationService->classificationByOverdue($maxOverdueDays);
                $classificationCounts[$classification->name] = ($classificationCounts[$classification->name] ?? 0) + 1;

                $matched = Client::where('bank_client_id', $bankClientId)->exists();
                if ($matched) {
                    $matchedCount++;
                } else {
                    $unmatched[] = $bankClientId;
                }

                $rows[] = [
                    'bank_client_id' => $bankClientId,
                    'full_name' => $fullName,
                    'classification' => $classification->name,
                    'matched' => $matched,
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
     */
    public function apply(string $encryptedPath, string $password, string $sourceLabel = ''): array
    {
        $decryptedPath = $this->decryptToTempFile($encryptedPath, $password);

        $summary = [
            'updated' => [],
            'skipped' => [],
            'unmatched' => [],
            'errors' => [],
        ];

        try {
            foreach ($this->parseRows($decryptedPath) as [$bankClientId, $fullName, $maxOverdueDays]) {
                try {
                    $client = Client::where('bank_client_id', $bankClientId)->with('classification')->first();
                    if (!$client) {
                        $summary['unmatched'][] = $bankClientId;
                        continue;
                    }

                    $classification = $this->classificationService->classificationByOverdue($maxOverdueDays);
                    $comment = 'Client classification update from ACC periodic report'
                        . ($sourceLabel !== '' ? " ({$sourceLabel})" : '');

                    $applied = $this->classificationService->applyClassificationIfWorse($client, $classification, $comment);

                    $entry = [
                        'client_id' => $client->id,
                        'bank_client_id' => $bankClientId,
                        'full_name' => $fullName,
                        'max_overdue_days' => $maxOverdueDays,
                        'classification' => $classification->name,
                    ];

                    $summary[$applied ? 'updated' : 'skipped'][] = $entry;
                } catch (\Throwable $e) {
                    Log::error("ACC import: failed processing bank_client_id {$bankClientId}: " . $e->getMessage());
                    $summary['errors'][] = ['bank_client_id' => $bankClientId, 'error' => $e->getMessage()];
                }
            }
        } finally {
            if (file_exists($decryptedPath)) {
                @unlink($decryptedPath);
            }
        }

        return $summary;
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

            if ($bankClientId === '' || !is_numeric($maxOverdueRaw)) {
                continue;
            }

            yield [$bankClientId, $fullName, (int) $maxOverdueRaw];
        }

        $spreadsheet->disconnectWorksheets();
    }
}
