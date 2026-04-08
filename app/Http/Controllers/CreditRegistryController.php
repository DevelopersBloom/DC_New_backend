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
use Illuminate\Http\JsonResponse;
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

//    public function sendL001(string $id): JsonResponse
//    {
//        $contract = Contract::find($id);
//
//        if (! $contract) {
//            return response()->json(['message' => 'Contract not found'], 404);
//        }
//
//        // 1. Generate XML
//        try {
//            $xml = $this->l001Service->generateL001Xml($contract);
//        } catch (Throwable $e) {
//            return response()->json([
//                'message' => 'Failed to generate L001 XML',
//                'error'   => $e->getMessage(),
//            ], 500);
//        }
//
//        // 2. Send to CBA
//        try {
//            $requestId = $this->soapClient->sendL001($xml, false);
//        } catch (\RuntimeException $e) {
//            return response()->json([
//                'message' => 'Failed to send L001 to Credit Registry',
//                'error'   => $e->getMessage(),
//            ], 502);
//        }
//
//        // 3. Poll for response (10 attempts × 500ms = 5 seconds max)
//        $maxTries  = 10;
//        $sleepMs   = 500;
//        $isReady   = false;
//
//        for ($i = 0; $i < $maxTries; $i++) {
//            try {
//                if ($this->soapClient->isResponsePrepared($requestId)) {
//                    $isReady = true;
//                    break;
//                }
//            } catch (\RuntimeException $e) {
//                return response()->json([
//                    'message'    => 'Failed to check response status',
//                    'request_id' => $requestId,
//                    'error'      => $e->getMessage(),
//                ], 502);
//            }
//
//            usleep($sleepMs * 1000);
//        }
//
//        // 4. Fetch response if ready
//        $responseXml = null;
//        if ($isReady) {
//            try {
//                $responseXml = $this->soapClient->getResponse($requestId);
//            } catch (\RuntimeException $e) {
//                return response()->json([
//                    'message'    => 'Failed to retrieve response',
//                    'request_id' => $requestId,
//                    'error'      => $e->getMessage(),
//                ], 502);
//            }
//        }
//
//        // 5. Return — 202 if still processing, 200 if response received
//        return response()->json([
//            'request_id'   => $requestId,
//            'is_ready'     => $isReady,
//            'response_xml' => $responseXml,
//        ], $isReady ? 200 : 202);
//    }
    public function testConnection()
    {
        $wsdl = config('credit_registry.wsdl');

        try {
            $content = file_get_contents($wsdl);
            dd('OK - WSDL reachable');
        } catch (\Throwable $e) {
            dd('FAILED', $e->getMessage());
        }
    }
    public function sendL001(string $id): JsonResponse
    {
        $contract = Contract::find($id);
        if (! $contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        try {
            $xml = $this->l001Service->generateL001Xml($contract);
            $requestId = $this->soapClient->sendL001($xml, false);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send L001',
                'error'   => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'request_id' => $requestId,
            'status'     => 'sent',
        ], 202);
    }
    function sendL001test($id, string $soapAction = 'L001')
    {
        $contract = Contract::find($id);

        if (!$contract) {
            return response()->json(['message' => 'Contract not found'], 404);
        }

        $username = env('CREDIT_REGISTRY_USERNAME', '');
        $password = env('CREDIT_REGISTRY_PASSWORD', '');

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
 xmlns:soapenv="http://www.w3.org/2003/05/soap-envelope"
 xmlns:urn="urn:cba-am:lnreg3"
 xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
   <soapenv:Header>
      <wsse:Security>
         <wsse:UsernameToken>
            <wsse:Username>{$username}</wsse:Username>
            <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">{$password}</wsse:Password>
         </wsse:UsernameToken>
      </wsse:Security>
   </soapenv:Header>
   <soapenv:Body>
      <urn:L001>
         <urn:ContractNum>{$contract->num}</urn:ContractNum>
         <urn:Test>123</urn:Test>
      </urn:L001>
   </soapenv:Body>
</soapenv:Envelope>
XML;

        $url        = env('CREDIT_REGISTRY_ENDPOINT', 'https://100.100.100.60:8888/DEGSHost');
        $certPath   = env('CREDIT_REGISTRY_CLIENT_CERT_PATH');
        $certPass   = env('CREDIT_REGISTRY_CLIENT_CERT_PASSWORD');
        $caCertPath = env('CREDIT_REGISTRY_CA_CERT_PATH');

        // env() returns strings — cast properly
        $verifyPeer = filter_var(env('CREDIT_REGISTRY_VERIFY_PEER', true), FILTER_VALIDATE_BOOLEAN);
        $verifyHost = filter_var(env('CREDIT_REGISTRY_VERIFY_PEER_NAME', false), FILTER_VALIDATE_BOOLEAN) ? 2 : 0;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/soap+xml; charset=utf-8; action="urn:' . $soapAction . '"',
                'Content-Length: ' . strlen($xml),
            ],
            CURLOPT_SSLCERT        => $certPath,
            CURLOPT_SSLCERTPASSWD  => $certPass,
            CURLOPT_CAINFO         => $caCertPath,
            CURLOPT_SSL_VERIFYPEER => $verifyPeer,
            CURLOPT_SSL_VERIFYHOST => $verifyHost,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            return response()->json([
                'message' => 'cURL error',
                'error'   => $error,
                'status'  => $status,
            ], 500);
        }

        return response()->json([
            'status'   => $status,
            'response' => $response,
        ]);
    }    /**
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
