<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports the periodic Central Bank / credit registry ("ACC") export and
 * upgrades client classifications based on the max overdue days reported
 * there, reusing ClientClassificationService::applyClassificationIfWorse()
 * so classification is never downgraded and the same reserve/write-off
 * posting cascade as the automatic job runs.
 *
 * The file is delivered password-protected (MS-OFFCRYPTO). PhpSpreadsheet
 * can't read encrypted OOXML directly, so decryption goes through Excel via
 * COM automation (requires the php com_dotnet extension and Microsoft Excel
 * installed on this server).
 */
class AccClassificationImportService
{
    private const DATA_START_ROW = 4;
    private const COL_BANK_CLIENT_ID = 3;
    private const COL_MAX_OVERDUE_DAYS = 16;

    public function __construct(private ClientClassificationService $classificationService)
    {
    }

    public function import(string $encryptedPath, string $password, string $sourceLabel = ''): array
    {
        $decryptedPath = $this->decryptToTempFile($encryptedPath, $password);

        $summary = [
            'updated' => [],
            'skipped' => [],
            'unmatched' => [],
            'errors' => [],
        ];

        try {
            foreach ($this->parseRows($decryptedPath) as [$bankClientId, $maxOverdueDays]) {
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
        if (!class_exists(\COM::class)) {
            throw new \RuntimeException(
                'Decrypting password-protected ACC files requires the PHP com_dotnet extension '
                . 'and Microsoft Excel to be installed on this server.'
            );
        }

        $tempDir = storage_path('app/tmp/acc-import');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $decryptedPath = $tempDir . '/' . (string) Str::uuid() . '.xlsx';

        $excel = new \COM('Excel.Application');
        $excel->Visible = false;
        $excel->DisplayAlerts = false;

        try {
            $workbook = $excel->Workbooks->Open($encryptedPath, false, false, null, $password);
        } catch (\Throwable $e) {
            $excel->Quit();
            $excel = null;
            throw new \RuntimeException('Failed to open the ACC file — wrong password or corrupt file.', 0, $e);
        }

        try {
            $workbook->Password = '';
            $workbook->SaveAs($decryptedPath, 51); // xlOpenXMLWorkbook (.xlsx, unprotected)
        } finally {
            $workbook->Close(false);
            $excel->Quit();
            $excel = null;
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
            $maxOverdueRaw = $sheet->getCellByColumnAndRow(self::COL_MAX_OVERDUE_DAYS, $row)->getValue();

            if ($bankClientId === '' || !is_numeric($maxOverdueRaw)) {
                continue;
            }

            yield [$bankClientId, (int) $maxOverdueRaw];
        }

        $spreadsheet->disconnectWorksheets();
    }
}
