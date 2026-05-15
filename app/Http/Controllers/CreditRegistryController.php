<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Modification;
use App\Services\CreditRegistryL001Service;
use App\Services\CreditRegistryL002Service;
use App\Services\CreditRegistryL003Service;
use App\Services\CreditRegistryL005Service;
use App\Services\CreditRegistryL006Service;
use App\Services\CreditRegistryRiskModificationXmlService;
use App\Services\Degs\DegsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CreditRegistryController extends Controller
{
    public function __construct(
        private CreditRegistryL001Service                $l001Service,
        private CreditRegistryL002Service                $l002Service,
        private CreditRegistryL003Service                $l003Service,
        private CreditRegistryL005Service                $l005Service,
        private CreditRegistryL006Service                $l006Service,
        private CreditRegistryRiskModificationXmlService $riskModXmlService,
        private DegsClient                               $degsClient,
    )
    {
    }

    // ================================================================
    // Connectivity
    // ================================================================

    /**
     * GET /credit-registry/test-connection
     * Checks if the .NET proxy is running and DEGS is reachable.
     */
    public function testConnection(): JsonResponse
    {
        $result = $this->degsClient->isAlive();

        if (!($result['ok'] ?? false)) {
            return response()->json([
                'status' => 'Proxy error',
                'message' => $result['error'] ?? 'Unknown error',
                'hint' => 'Run: sudo systemctl status degs-proxy',
            ], 502);
        }

        return response()->json([
            'status' => $result['alive'] ? 'Connected!' : 'Proxy OK but DEGS unreachable',
            'alive' => $result['alive'] ?? false,
            'raw' => $result['raw'] ?? null,
        ]);
    }

    // ================================================================
    // L001 — New loan registration
    // ================================================================

    /**
     * POST /credit-registry/contracts/{id}/send-l001
     * Generates L001 XML and sends it to DEGS. Returns requestId.
     */
    public function sendL001(string $id): JsonResponse
    {
        $contract = Contract::find($id);
        if (!$contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        try {
            $xml = $this->l001Service->generateL001Xml($contract);
            $result = $this->degsClient->sendL001($xml);

            if (!($result['ok'] ?? false)) {
                return response()->json([
                    'message' => 'DEGS rejected the request',
                    'error' => $result['error'] ?? 'Unknown error',
                    'inner' => $result['inner'] ?? null,
                ], 502);
            }

            return response()->json([
                'request_id' => $result['requestId'],
                'status' => 'sent',
            ], 202);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send L001',
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * GET /credit-registry/contracts/{id}/download-l001
     * Download L001 XML without sending.
     */
    public function downloadL001(string $id): StreamedResponse|JsonResponse
    {
        $contract = Contract::find($id);
        if (!$contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        $xml = $this->l001Service->generateL001Xml($contract);
        $filename = 'L001_' . ($contract->num ?? $contract->id) . '_' . now()->format('Y-m-d_His') . '.xml';

        return response()->streamDownload(
            fn() => print($xml),
            $filename,
            ['Content-Type' => 'application/xml']
        );
    }

    // ================================================================
    // L002 — Loan modification
    // ================================================================

    /**
     * POST /credit-registry/contracts/{id}/send-l002
     */
    public function sendL002(string $id): JsonResponse
    {
        $contract = Contract::find($id);
        if (!$contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        try {
            $xml = $this->l002Service->generateL002Xml((int)$contract->id);
            $result = $this->degsClient->sendL002($xml);

            if (!($result['ok'] ?? false)) {
                return response()->json([
                    'message' => 'DEGS rejected the request',
                    'error' => $result['error'] ?? 'Unknown error',
                    'inner' => $result['inner'] ?? null,
                ], 502);
            }

            return response()->json([
                'request_id' => $result['requestId'],
                'status' => 'sent',
            ], 202);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send L002',
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * GET /credit-registry/contracts/{id}/download-l002
     */
    public function downloadL002(string $id): StreamedResponse|JsonResponse
    {
        $contract = Contract::find($id);
        if (!$contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        $xml = $this->l002Service->generateL002Xml((int)$contract->id);
        $filename = 'L002_' . ($contract->num ?? $contract->id) . '_' . now()->format('Y-m-d_His') . '.xml';

        return response()->streamDownload(
            fn() => print($xml),
            $filename,
            ['Content-Type' => 'application/xml']
        );
    }

    // ================================================================
    // L003 — Loan deletion
    // ================================================================

    /**
     * POST /credit-registry/contracts/{id}/send-l003
     * Body (optional): { "reason": "..." }
     */
    public function sendL003(Request $request, string $id): JsonResponse
    {
        $contract = Contract::find($id);
        if (!$contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        try {
            $reason = $request->input('reason', 'Սխալ գրանցում');
            $xml = $this->l003Service->generateL003Xml((int)$contract->id, $reason);
            $result = $this->degsClient->sendL003($xml);

            if (!($result['ok'] ?? false)) {
                return response()->json([
                    'message' => 'DEGS rejected the request',
                    'error' => $result['error'] ?? 'Unknown error',
                    'inner' => $result['inner'] ?? null,
                ], 502);
            }

            return response()->json([
                'request_id' => $result['requestId'],
                'status' => 'sent',
            ], 202);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send L003',
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * GET /credit-registry/contracts/{id}/download-l003
     */
    public function downloadL003(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $contract = Contract::find($id);
        if (!$contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        $reason = $request->input('reason', 'Սխալ գրանցում');
        $xml = $this->l003Service->generateL003Xml((int)$contract->id, $reason);
        $filename = 'L003_' . ($contract->num ?? $contract->id) . '_' . now()->format('Y-m-d_His') . '.xml';

        return response()->streamDownload(
            fn() => print($xml),
            $filename,
            ['Content-Type' => 'application/xml']
        );
    }

    // ================================================================
    // Response polling
    // ================================================================

    /**
     * POST /credit-registry/response-prepared
     * Body: { "request_id": 12345 }
     */
    public function isResponsePrepared(Request $request): JsonResponse
    {
        $data = $request->validate(['request_id' => 'required|integer']);
        $result = $this->degsClient->isResponsePrepared($data['request_id']);

        return response()->json($result);
    }

    /**
     * POST /credit-registry/get-response
     * Body: { "request_id": 12345 }
     * Returns parsed Answer XML from CBA (OK or ERROR with error list).
     */
    public function getResponse(Request $request): JsonResponse
    {
        $data   = $request->validate(['request_id' => 'required|integer']);
        $result = $this->degsClient->getResponse($data['request_id']);

        if (! ($result['ok'] ?? false)) {
            return response()->json(['ok' => false, 'error' => $result['error'] ?? 'Unknown error'], 502);
        }

        $xmlString = $result['result'] ?? '';

        if (empty($xmlString)) {
            return response()->json(['ok' => false, 'error' => 'Empty response from DEGS'], 502);
        }

        return response()->json($this->parseAnswerXml($xmlString));
    }

    private function parseAnswerXml(string $xml): array
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);

        if ($doc === false) {
            return ['ok' => false, 'error' => 'Failed to parse response XML', 'raw' => $xml];
        }

        $header = [
            'application' => (string) ($doc->Header->Application ?? ''),
            'message_id'  => (string) ($doc->Header->MessageId ?? ''),
            'answer_date' => (string) ($doc->Header->AnswerDate ?? ''),
            'answer_time' => (string) ($doc->Header->AnswerTime ?? ''),
        ];

        $infoType = strtoupper((string) ($doc->Info['Type'] ?? ''));

        if ($infoType === 'OK') {
            return [
                'ok'     => true,
                'status' => 'OK',
                'header' => $header,
                'reason' => (string) ($doc->Info->Reason ?? ''),
            ];
        }

        $errors = [];
        $info   = $doc->Info;

        $codes        = $info->ErrorCode ?? [];
        $descriptions = $info->ErrorDescription ?? [];
        $qualifiers   = $info->ErrorQualifyer ?? [];

        $count = max(count((array) $codes), 1);
        for ($i = 0; $i < $count; $i++) {
            $errors[] = [
                'code'        => isset($codes[$i])        ? (string) $codes[$i]        : (string) ($codes ?? ''),
                'description' => isset($descriptions[$i]) ? (string) $descriptions[$i] : (string) ($descriptions ?? ''),
                'qualifier'   => isset($qualifiers[$i])   ? (string) $qualifiers[$i]   : null,
            ];
        }

        return [
            'ok'     => false,
            'status' => 'ERROR',
            'header' => $header,
            'errors' => $errors,
        ];
    }

    // ================================================================
    // RISK modifications batch
    // ================================================================

    /**
     * GET /credit-registry/risk-modifications/download
     * Generate and download XML for all unsent RISK modifications.
     * Marks exported rows as sent.
     */
    public function downloadUnsentRiskModifications(): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $mods = Modification::query()
            ->where('is_sent', false)
            ->where('modification_type', 'RISK')
            ->where('subject_type', Client::class)
            ->orderBy('id')
            ->get();

        if ($mods->isEmpty()) {
            return response()->json(['message' => 'No unsent RISK modifications found'], 404);
        }

        $xml = $this->riskModXmlService->generateUnsentRiskBatchXml($mods);
        $filename = 'RISK_MODIFICATIONS_' . now()->format('Y-m-d_His') . '.xml';
        $path = storage_path('app/tmp/' . $filename);

        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $xml);

        Modification::query()
            ->whereIn('id', $mods->pluck('id'))
            ->update(['is_sent' => true, 'sent_at' => now()]);

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    // ================================================================
    // L005 — LoanUseField / LoanUsePurpose թարմացում
    // ================================================================

    /**
     * GET /{id}/credit-registry/l005
     * Query: field_type, new_value, old_value (optional)
     */
    public function downloadL005(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $contract = Contract::find($id);
        if (! $contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        $fieldType = $request->input('field_type');
        $newValue  = $request->input('new_value');

        if (! $fieldType || ! $newValue) {
            return response()->json(['message' => 'field_type and new_value are required'], 422);
        }

        try {
            $xml = $this->l005Service->generateL005Xml(
                contractId: (int) $contract->id,
                fieldType: $fieldType,
                newValue: $newValue,
                oldValue: $request->input('old_value'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $filename = 'L005_' . ($contract->num ?? $contract->id) . '_' . now()->format('Y-m-d_His') . '.xml';

        return response()->streamDownload(fn () => print($xml), $filename, [
            'Content-Type'        => 'application/xml',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * POST /{id}/credit-registry/l005/send
     * Body: field_type, new_value, old_value (optional)
     */
    public function sendL005(Request $request, string $id): JsonResponse
    {
        $contract = Contract::find($id);
        if (! $contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        $fieldType = $request->input('field_type');
        $newValue  = $request->input('new_value');

        if (! $fieldType || ! $newValue) {
            return response()->json(['message' => 'field_type and new_value are required'], 422);
        }

        try {
            $xml = $this->l005Service->generateL005Xml(
                contractId: (int) $contract->id,
                fieldType: $fieldType,
                newValue: $newValue,
                oldValue: $request->input('old_value'),
            );

            $result = $this->degsClient->sendL005($xml);

            if (! ($result['ok'] ?? false)) {
                return response()->json([
                    'message' => 'DEGS rejected the request',
                    'error'   => $result['error'] ?? 'Unknown error',
                ], 502);
            }

            return response()->json([
                'request_id' => $result['requestId'],
                'status'     => 'sent',
                'message'    => 'L005 sent successfully',
            ], 202);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to send L005', 'error' => $e->getMessage()], 502);
        }
    }

    // ================================================================
    // L006 — LoanUseField / LoanUsePurpose ջնջում
    // ================================================================

    /**
     * GET /{id}/credit-registry/l006
     * Query: data_to_delete
     */
    public function downloadL006(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $contract = Contract::find($id);
        if (! $contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        $dataToDelete = $request->input('data_to_delete');
        if (! $dataToDelete) {
            return response()->json(['message' => 'data_to_delete is required'], 422);
        }

        try {
            $xml = $this->l006Service->generateL006Xml((int) $contract->id, $dataToDelete);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $filename = 'L006_' . ($contract->num ?? $contract->id) . '_' . now()->format('Y-m-d_His') . '.xml';

        return response()->streamDownload(fn () => print($xml), $filename, [
            'Content-Type'        => 'application/xml',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * POST /{id}/credit-registry/l006/send
     * Body: data_to_delete
     */
    public function sendL006(Request $request, string $id): JsonResponse
    {
        $contract = Contract::find($id);
        if (! $contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        $dataToDelete = $request->input('data_to_delete');
        if (! $dataToDelete) {
            return response()->json(['message' => 'data_to_delete is required'], 422);
        }

        try {
            $xml    = $this->l006Service->generateL006Xml((int) $contract->id, $dataToDelete);
            $result = $this->degsClient->sendL006($xml);

            if (! ($result['ok'] ?? false)) {
                return response()->json([
                    'message' => 'DEGS rejected the request',
                    'error'   => $result['error'] ?? 'Unknown error',
                ], 502);
            }

            return response()->json([
                'request_id' => $result['requestId'],
                'status'     => 'sent',
                'message'    => 'L006 sent successfully',
            ], 202);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to send L006', 'error' => $e->getMessage()], 502);
        }
    }
}
