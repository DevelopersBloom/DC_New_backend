<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractModification;
use App\Models\Modification;
use App\Services\CreditRegistryL001Service;
use App\Services\CreditRegistryL002Service;
use App\Services\CreditRegistryL003Service;
use App\Services\CreditRegistrySoapClient;
use App\Services\CreditRegistryRiskModificationXmlService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CreditRegistryController extends Controller
{
    public function __construct(
        private CreditRegistryL001Service $l001Service,
        private CreditRegistryL002Service $l002Service,
        private CreditRegistryL003Service $l003Service,
        private CreditRegistryRiskModificationXmlService $riskModXmlService,
//        private CreditRegistrySoapClient $soapClient,
    ) {
    }

    public function send(string $id): Response
    {
        dd($id);
        $contract = Contract::find($id);

        if (! $contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        $xml = $this->l001Service->generateL001Xml($contract);
        $requestId = $this->soapClient->sendL001($xml, false);

        $maxTries = 10;
        $sleepMs = 500;

        $isReady = false;
        for ($i = 0; $i < $maxTries; $i++) {
            if ($this->soapClient->isResponsePrepared($requestId)) {
                $isReady = true;
                break;
            }
            usleep($sleepMs * 1000);
        }

        $responseXml = null;
        if ($isReady) {
            $responseXml = $this->soapClient->getResponse($requestId);
        }

        return response()->json([
            'request_id' => $requestId,
            'is_ready' => $isReady,
            'response_xml' => $responseXml,
        ]);
    }
    /**
     * Generate and download L001 XML for a single contract (Credit Registry).
     */
    public function downloadL001(string $id): StreamedResponse|Response
    {
        $contract = Contract::find($id);

        if (! $contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        $xml = $this->l001Service->generateL001Xml($contract);
        $filename = 'L001_' . ($contract->num ?? $contract->id) . '_' . now()->format('Y-m-d_His') . '.xml';

        return response()->streamDownload(
            function () use ($xml) {
                echo $xml;
            },
            $filename,
            [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    /**
     * Generate and download L002 XML for a single contract (Credit Registry loan modification).
     */
    public function downloadL002(string $id): StreamedResponse|Response
    {
        $contract = Contract::find($id);
        if (! $contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }
        $xml = $this->l002Service->generateL002Xml((int) $contract->id);
        $filename = 'L002_' . ($contract->num ?? $contract->id) . '_' . now()->format('Y-m-d_His') . '.xml';

        return response()->streamDownload(
            function () use ($xml) {
                echo $xml;
            },
            $filename,
            [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    /**
     * Generate and download L003 XML (Delete request).
     */
    public function downloadL003(Request $request, string $id): StreamedResponse|Response
    {
        $contract = Contract::find($id);
        if (!$contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        $reason = $request->input('reason', 'Սխալ գրանցում');

        $xml = $this->l003Service->generateL003Xml((int) $contract->id, $reason);
        $filename = 'L003_' . ($contract->num ?? $contract->id) . '_' . now()->format('Y-m-d_His') . '.xml';

        return response()->streamDownload(
            function () use ($xml) {
                echo $xml;
            },
            $filename,
            [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    /**
     * Generate and download ONE XML file containing all unsent RISK modifications.
     * Marks exported rows as sent.
     */
    public function downloadUnsentRiskModifications(): \Symfony\Component\HttpFoundation\BinaryFileResponse
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

}
