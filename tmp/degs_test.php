<?php

namespace App\Services\Degs;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DEGS Client — Laravel wrapper for the local .NET proxy.
 *
 * Architecture:
 *   Laravel (PHP) ──HTTP──▶ .NET 8 proxy (127.0.0.1:5555)
 *                                │
 *                                │ WCF wsHttpBinding + WS-Security X.509
 *                                ▼
 *                        DEGS API (CBA)
 *                https://100.100.100.60:8888/DEGSHost
 *
 * Setup:
 *   sudo bash setup.sh   (installs .NET 8, builds proxy, registers systemd service)
 *   curl http://127.0.0.1:5555/isalive   (smoke test)
 *
 * All methods return an array with:
 *   - ok        (bool)    — success/failure
 *   - error     (string)  — error message if ok = false
 *   - inner     (string)  — inner error from .NET / DEGS
 *   - raw       (string)  — raw XML response from DEGS (for debugging)
 *   - + method-specific fields (requestId, result, alive, prepared, exists)
 */
class DegsClient
{
    private const PROXY_URL = 'http://127.0.0.1:5555';
    private const TIMEOUT   = 30;

    // ================================================================
    // Internal HTTP call to proxy
    // ================================================================

    private function call(string $endpoint, array $payload = [], string $method = 'POST'): array
    {
        try {
            $req = Http::timeout(self::TIMEOUT);

            $response = $method === 'GET'
                ? $req->get(self::PROXY_URL . $endpoint)
                : $req->post(self::PROXY_URL . $endpoint, $payload);

            Log::channel('degs')->debug("Proxy $endpoint", [
                'status'  => $response->status(),
                'payload' => $payload,
                'body'    => $response->body(),
            ]);

            return $response->json() ?? ['ok' => false, 'error' => 'Empty response from proxy'];

        } catch (\Throwable $e) {
            Log::channel('degs')->error("Proxy unreachable", ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Proxy unreachable: ' . $e->getMessage()];
        }
    }

    // ================================================================
    // Public API
    // ================================================================

    /**
     * Check DEGS connectivity.
     *
     * @return array{ok: bool, alive: bool, raw?: string, error?: string}
     */
    public function isAlive(): array
    {
        return $this->call('/isalive', [], 'GET');
    }

    /**
     * Send a document request to DEGS.
     *
     * @param  string $appName  application name (e.g. 'ACREDIT')
     * @param  string $docType  document type (e.g. 'L001', 'L002', 'L003')
     * @param  bool   $isDelay  non-urgent flag
     * @param  string $xml      XML body (L001/L002/L003 content)
     * @return array{ok: bool, requestId: int, raw?: string, error?: string}
     */
    public function sendRequest(string $appName, string $docType, bool $isDelay, string $xml): array
    {
        return $this->call('/send-request', [
            'appName' => $appName,
            'docType' => $docType,
            'isDelay' => $isDelay,
            'xml'     => $xml,
        ]);
    }

    /**
     * Get response for a previously sent request (old format).
     *
     * @return array{ok: bool, result: string, raw?: string, error?: string}
     */
    public function getResponse(int $requestId): array
    {
        return $this->call('/get-response', ['requestId' => $requestId]);
    }

    /**
     * Get response in new format.
     *
     * @return array{ok: bool, result: string, raw?: string, error?: string}
     */
    public function getResponseNew(int $requestId): array
    {
        return $this->call('/get-response-new', ['requestId' => $requestId]);
    }

    /**
     * Check if a response is ready.
     *
     * @return array{ok: bool, prepared: bool, raw?: string, error?: string}
     */
    public function isResponsePrepared(int $requestId): array
    {
        return $this->call('/is-response-prepared', ['requestId' => $requestId]);
    }

    /**
     * Check if a request ID exists.
     *
     * @return array{ok: bool, exists: bool, raw?: string, error?: string}
     */
    public function requestIdExists(int $requestId): array
    {
        return $this->call('/request-id-exists', ['requestId' => $requestId]);
    }

    /**
     * Raw passthrough — custom action + body XML.
     *
     * @return array{ok: bool, result: string, raw?: string, error?: string}
     */
    public function customRequest(string $code, string $body): array
    {
        return $this->call('/custom-request', [
            'code' => $code,
            'body' => $body,
        ]);
    }

    // ================================================================
    // High-level helpers
    // ================================================================

    /**
     * Send L001 (new loan registration).
     *
     * @return array{ok: bool, requestId: int, raw?: string, error?: string}
     */
    public function sendL001(string $xml, bool $isDelay = false): array
    {
        return $this->sendRequest('ACREDIT', 'L001', $isDelay, $xml);
    }

    /**
     * Send L002 (loan modification).
     *
     * @return array{ok: bool, requestId: int, raw?: string, error?: string}
     */
    public function sendL002(string $xml, bool $isDelay = false): array
    {
        return $this->sendRequest('ACREDIT', 'L002', $isDelay, $xml);
    }

    /**
     * Send L003 (loan deletion).
     *
     * @return array{ok: bool, requestId: int, raw?: string, error?: string}
     */
    public function sendL003(string $xml, bool $isDelay = false): array
    {
        return $this->sendRequest('ACREDIT', 'L003', $isDelay, $xml);
    }

    /**
     * Send a document and wait for the response (polling).
     *
     * @param  int $maxWaitSeconds     max polling time (default 60s)
     * @param  int $pollIntervalSeconds sleep between polls (default 2s)
     * @return array{ok: bool, requestId?: int, result?: string, error?: string}
     */
    public function sendAndWait(
        string $appName,
        string $docType,
        bool   $isDelay,
        string $xml,
        int    $maxWaitSeconds      = 60,
        int    $pollIntervalSeconds = 2
    ): array {
        $send = $this->sendRequest($appName, $docType, $isDelay, $xml);

        if (!($send['ok'] ?? false) || empty($send['requestId'])) {
            return $send;
        }

        $requestId = $send['requestId'];
        $deadline  = time() + $maxWaitSeconds;

        while (time() < $deadline) {
            $check = $this->isResponsePrepared($requestId);

            if (!($check['ok'] ?? false)) {
                return $check;
            }

            if ($check['prepared'] ?? false) {
                $response              = $this->getResponse($requestId);
                $response['requestId'] = $requestId;
                return $response;
            }

            sleep($pollIntervalSeconds);
        }

        return [
            'ok'        => false,
            'requestId' => $requestId,
            'error'     => "Timeout: response not prepared in {$maxWaitSeconds}s",
        ];
    }
}
