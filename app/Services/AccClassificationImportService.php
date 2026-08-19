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
    private const COL_SOCIAL_CARD_NUMBER = 5;
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

        [$nameIndex, $socialCardById, $classificationIdById] = $this->buildClientDirectory();

        $classificationCounts = [];
        $rows = [];
        $matchedCount = 0;
        $unmatched = [];

        try {
            foreach ($this->parseRows($decryptedPath) as [$bankClientId, $socialCardNumber, $fullName, $maxOverdueDays]) {
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
                    'social_card_number' => $socialCardNumber,
                    'full_name' => $fullName,
                    'classification' => $classification->name,
                    'matched' => $match['status'] === 'matched',
                    'match_status' => $match['status'], // matched | unmatched | ambiguous
                    // Informational only — doesn't affect matching/status, just flags
                    // a matched client whose recorded social card number differs.
                    'social_card_number_mismatch' => $match['status'] === 'matched'
                        && $this->socialCardMismatch($socialCardById[$match['client_id']] ?? null, $socialCardNumber),
                    // True when this row wouldn't actually change anything — the
                    // client's current classification already equals what the ACC
                    // overdue figure computes to.
                    'classification_matches_current' => $match['status'] === 'matched'
                        && ($classificationIdById[$match['client_id']] ?? null) === $classification->id,
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

        [$nameIndex, $socialCardById, $classificationIdById] = $this->buildClientDirectory();

        $summary = [
            'updated' => [],
            'skipped' => [],
            'unmatched' => [],
            'ambiguous' => [],
            'errors' => [],
        ];

        try {
            foreach ($this->parseRows($decryptedPath) as [$bankClientId, $socialCardNumber, $fullName, $maxOverdueDays]) {
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
                        // Informational only — doesn't block/undo the classification update.
                        'social_card_number_mismatch' => $this->socialCardMismatch(
                            $socialCardById[$client->id] ?? null, $socialCardNumber
                        ),
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
     * Builds the lookups apply()/preview() need, in one pass over Client:
     *  - a name index: every normalized name variant → the client id(s) that
     *    produce it, so each ACC row resolves with a single array lookup
     *    instead of a query per row. A name shared by more than one client
     *    ends up with 2+ ids under the same key, which resolveClientMatch()
     *    reads as "ambiguous" rather than picking one arbitrarily.
     *  - a client id → social_card_number map, for the (name-match-only)
     *    social card number cross-check.
     *  - a client id → current classification_id map, so preview() can flag
     *    rows where the ACC-derived classification already matches what the
     *    client currently has on our site (nothing would actually change).
     *
     * @return array{0: array<string, int[]>, 1: array<int, ?string>, 2: array<int, ?int>}
     */
    private function buildClientDirectory(): array
    {
        $nameIndex = [];
        $socialCardById = [];
        $classificationIdById = [];

        Client::query()
            ->whereNull('deleted_at')
            ->select(['id', 'type', 'name', 'surname', 'company_name', 'social_card_number', 'classification_id'])
            ->chunk(1000, function ($clients) use (&$nameIndex, &$socialCardById, &$classificationIdById) {
                foreach ($clients as $client) {
                    foreach ($this->nameKeysFor($client) as $key) {
                        $nameIndex[$key][] = $client->id;
                    }
                    $socialCardById[$client->id] = $client->social_card_number;
                    $classificationIdById[$client->id] = $client->classification_id;
                }
            });

        return [$nameIndex, $socialCardById, $classificationIdById];
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

    /**
     * Cross-check only — matching is still done by name. Returns true when
     * both sides have a value and they disagree, so the caller can surface a
     * warning without it affecting matched/unmatched status.
     */
    private function socialCardMismatch(?string $clientValue, string $accValue): bool
    {
        $acc = $this->normalizeIdentifier($accValue);
        if ($acc === '') {
            return false;
        }

        $client = $this->normalizeIdentifier((string) $clientValue);
        if ($client === '') {
            return false;
        }

        return $client !== $acc;
    }

    private function normalizeIdentifier(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', trim($value)) ?? '', 'UTF-8');
    }

    private function normalizeName(string $value): string
    {
        // PCRE's \s only covers ASCII whitespace, even under /u — bank/Excel
        // exports commonly use U+00A0 (no-break space) between name parts,
        // which would otherwise silently defeat the match. Fold it (and other
        // Unicode space separators) to a regular space before collapsing.
        $value = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{FEFF}]/u', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $value = mb_strtolower($value, 'UTF-8');

        // Uppercase ACC reports spell "և" as the two letters "ԵՎ" (it has no
        // uppercase form of its own), which lowercases to "ևv"-as-two-letters —
        // while our own records normally use the single ligature "և". Fold both
        // that ligature and the old-orthography "եւ" spelling to the same
        // two-letter form so a client stored either way still matches.
        return str_replace(['և', 'եւ'], 'եվ', $value);
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
            $socialCardNumber = trim((string) $sheet->getCellByColumnAndRow(self::COL_SOCIAL_CARD_NUMBER, $row)->getValue());
            $fullName = trim((string) $sheet->getCellByColumnAndRow(self::COL_FULL_NAME, $row)->getValue());
            $maxOverdueRaw = $sheet->getCellByColumnAndRow(self::COL_MAX_OVERDUE_DAYS, $row)->getValue();

            if ($fullName === '' || !is_numeric($maxOverdueRaw)) {
                continue;
            }

            yield [$bankClientId, $socialCardNumber, $fullName, (int) $maxOverdueRaw];
        }

        $spreadsheet->disconnectWorksheets();
    }
}
