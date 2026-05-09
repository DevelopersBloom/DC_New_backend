<?php

namespace App\Services;

/**
 * HTTP client facade over the local .NET 8 DEGS proxy (127.0.0.1:5555).
 *
 * The proxy handles all WCF / WS-Security complexity. This class keeps
 * the same public interface so DegsClient and controllers need no changes.
 */
class CreditRegistrySoapClient
{
    private string $proxyUrl;
    private string $appName;

    public function __construct()
    {
        $this->proxyUrl = rtrim(config('credit_registry.proxy_url', 'http://127.0.0.1:5555'), '/');
        $this->appName  = config('credit_registry.app_name', 'LNREG3');
    }

    // ================================================================
    // Public API (same signatures as before)
    // ================================================================

    public function isAlive(): bool
    {
        $r = $this->get('/isalive');
        return (bool) ($r['alive'] ?? false);
    }

    public function sendL001(string $xmlContent, bool $dryRun = false): int
    {
        return $this->sendRequest('L001', $xmlContent, $dryRun);
    }

    public function sendL002(string $xmlContent, bool $dryRun = false): int
    {
        return $this->sendRequest('L002', $xmlContent, $dryRun);
    }

    public function sendL003(string $xmlContent, bool $dryRun = false): int
    {
        return $this->sendRequest('L003', $xmlContent, $dryRun);
    }

    public function sendL005(string $xmlContent, bool $dryRun = false): int
    {
        return $this->sendRequest('L005', $xmlContent, $dryRun);
    }

    public function sendL006(string $xmlContent, bool $dryRun = false): int
    {
        return $this->sendRequest('L006', $xmlContent, $dryRun);
    }

    public function isResponsePrepared(int $requestId): bool
    {
        $r = $this->post('/is-response-prepared', ['requestId' => $requestId]);
        return (bool) ($r['prepared'] ?? false);
    }

    public function getResponse(int $requestId): string
    {
        $r = $this->post('/get-response', ['requestId' => $requestId]);
        return (string) ($r['result'] ?? '');
    }

    // ================================================================
    // Internal
    // ================================================================

    private function sendRequest(string $docType, string $xmlContent, bool $dryRun): int
    {
        if ($dryRun) {
            \Log::debug('DEGS DryRun', ['docType' => $docType, 'xml' => $xmlContent]);
            return 0;
        }

        $r = $this->post('/send-request', [
            'appName' => $this->appName,
            'docType' => $docType,
            'isDelay' => false,
            'xml'     => $xmlContent,
        ]);

        return (int) ($r['requestId'] ?? 0);
    }

    private function get(string $path): array
    {
        $ch = curl_init($this->proxyUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('DEGS proxy cURL error: ' . $err);
        }
        if ($code >= 400) {
            throw new \RuntimeException('DEGS proxy HTTP ' . $code . ': ' . substr((string) $body, 0, 300));
        }

        return json_decode((string) $body, true) ?? [];
    }

    private function post(string $path, array $data): array
    {
        $json = json_encode($data);
        $ch   = curl_init($this->proxyUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('DEGS proxy cURL error: ' . $err);
        }

        $decoded = json_decode((string) $body, true) ?? [];

        if ($code >= 400 || ! ($decoded['ok'] ?? false)) {
            throw new \RuntimeException(
                'DEGS proxy error: ' . ($decoded['error'] ?? substr((string) $body, 0, 300))
            );
        }

        return $decoded;
    }
}
