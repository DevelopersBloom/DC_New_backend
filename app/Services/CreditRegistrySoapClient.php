<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;

class CreditRegistrySoapClient
{
    private const ACTION_NS = 'http://tempuri.org/';

    // Defaults — overridden by config/credit_registry.php values
    private string $endpoint;
    private string $appName;
    private string $certPath;
    private string $keyPath;
    private string $caPath;
    private ?string $certPassword;
    private bool $verifySsl;

    public function __construct()
    {
        $this->endpoint      = config('credit_registry.endpoint', 'https://100.100.100.60:8888/DEGSHost');
        $this->appName       = config('credit_registry.app_name', 'LNREG3');
        $this->certPath      = config('credit_registry.client_cert_path', '');
        // If no separate key file — combined PEM (cert + key in one file)
        $this->keyPath       = config('credit_registry.client_key_path') ?: $this->certPath;
        $this->caPath        = config('credit_registry.ca_cert_path', '');
        $this->certPassword  = config('credit_registry.client_cert_password') ?: null;
        $this->verifySsl     = (bool) config('credit_registry.verify_peer', false);
    }

    private const WSU_NS  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    private const WSSE_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    private const WSA_NS  = 'http://www.w3.org/2005/08/addressing';
    private const SOAP_NS = 'http://www.w3.org/2003/05/soap-envelope';
    private const DSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';

    private const X509_VALUETYPE   = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
    private const B64_ENCODINGTYPE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';
    private const THUMB_VALUETYPE  = 'http://docs.oasis-open.org/wss/oasis-wss-soap-message-security-1.1#ThumbprintSHA1';

    // Digest algorithm — many WCF stacks expect xmlenc#sha256
    private const DIGEST_ALG  = 'http://www.w3.org/2001/04/xmlenc#sha256';
    private const SIG_ALG     = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    private const C14N_ALG    = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    private const EXC14N_NS   = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    private const INCLUSIVE_PREFIX_LIST = 's a u o';

    private string $bstId = '';
    private string $actionId = '_action';
    private string $messageIdId = '_msgid';

    // ================================================================
    // Public API
    // ================================================================

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
        $body     = '<tns:IsResponsePrepared xmlns:tns="http://tempuri.org/">'
            . '<tns:requsetId>' . $requestId . '</tns:requsetId>'
            . '</tns:IsResponsePrepared>';
        $response = $this->dispatch('IsResponsePrepared', $body);
        $dom      = new DOMDocument();
        $dom->loadXML($response);
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[local-name()="IsResponsePreparedResult"]');
        return $nodes->length > 0
            && strtolower(trim($nodes->item(0)->textContent)) === 'true';
    }

    public function getResponse(int $requestId): string
    {
        $body = '<tns:GetResponse xmlns:tns="http://tempuri.org/">'
            . '<tns:requsetId>' . $requestId . '</tns:requsetId>'
            . '</tns:GetResponse>';
        return $this->dispatch('GetResponse', $body);
    }

    public function isAlive(): bool
    {
        try {
            $body     = '<tns:IsAlive xmlns:tns="http://tempuri.org/"/>';
            $response = $this->dispatch('IsAlive', $body);
            $dom      = new DOMDocument();
            @$dom->loadXML($response);
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query('//*[local-name()="IsAliveResult"]');
            return $nodes->length > 0
                && strtolower(trim($nodes->item(0)->textContent)) === 'true';
        } catch (\Throwable $e) {
            \Log::warning('DEGS isAlive failed: ' . $e->getMessage());
            return false;
        }
    }

    // ================================================================
    // Internal — SendRequest
    // ================================================================

    private function sendRequest(string $docType, string $xmlContent, bool $dryRun): int
    {
        $escapedXml = htmlspecialchars($xmlContent, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $body = '<tns:SendRequest xmlns:tns="http://tempuri.org/">'
            . '<tns:AppName>' . $this->appName . '</tns:AppName>'
            . '<tns:DocType>' . $docType . '</tns:DocType>'
            . '<tns:IsDelay>false</tns:IsDelay>'
            . '<tns:xml>' . $escapedXml . '</tns:xml>'
            . '</tns:SendRequest>';

        if ($dryRun) {
            $envelope = $this->buildEnvelope('SendRequest', $body);
            $signed   = $this->signEnvelope($envelope);
            \Log::debug('DEGS DryRun', ['xml' => $signed]);
            return 0;
        }

        $response = $this->dispatch('SendRequest', $body);
        return $this->extractSendRequestResult($response);
    }

    // ================================================================
    // Pipeline
    // ================================================================

    private function dispatch(string $action, string $bodyContent): string
    {
        $envelope = $this->buildEnvelope($action, $bodyContent);
        $signed   = $this->signEnvelope($envelope);
        return $this->sendViaCurl($action, $signed);
    }

    // ================================================================
    // Step 1 — SOAP Envelope
    // Կառուցվածք Security-ում:
    //   BST        (AlwaysToRecipient — FIRST)
    //   Timestamp
    //   [Signature-ն signEnvelope()-ն կավելացնի]
    // ================================================================

    private function buildEnvelope(string $action, string $bodyContent): string
    {
        $actionUrl   = self::ACTION_NS . $action;
        $msgId       = 'urn:uuid:' . $this->uuid4();
        $now         = gmdate('Y-m-d\TH:i:s\Z');
        $expires     = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
        $this->bstId = 'bst-' . bin2hex(random_bytes(8));

        if (! is_file($this->certPath)) {
            throw new \RuntimeException(
                'DEGS client certificate not found: ' . $this->certPath
                . '. Set CREDIT_REGISTRY_CLIENT_CERT_PATH in .env'
            );
        }
        if (! is_file($this->keyPath)) {
            throw new \RuntimeException(
                'DEGS private key not found: ' . $this->keyPath
                . '. Set CREDIT_REGISTRY_CLIENT_KEY_PATH in .env'
            );
        }

        $rawPem  = file_get_contents($this->certPath);
        $certB64 = str_replace(["\r", "\n", " "], '', preg_replace('/-----[^-]+-----/', '', $rawPem));

        // Signature-ն ՉԿԱ envelope-ում — signEnvelope()-ն կavoid digest-ի խնդիրը
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<s:Envelope'
            .   ' xmlns:s="'  . self::SOAP_NS . '"'
            .   ' xmlns:a="'  . self::WSA_NS  . '"'
            .   ' xmlns:u="'  . self::WSU_NS  . '"'
            .   ' xmlns:o="'  . self::WSSE_NS . '"'
            .   ' xmlns:ds="' . self::DSIG_NS . '">'
            .   '<s:Header>'
            .     '<a:Action s:mustUnderstand="1" u:Id="' . $this->actionId . '">' . $actionUrl . '</a:Action>'
            .     '<a:MessageID u:Id="' . $this->messageIdId . '">' . $msgId . '</a:MessageID>'
            .     '<a:To s:mustUnderstand="1" u:Id="_to">' . $this->endpoint . '</a:To>'
            .     '<o:Security s:mustUnderstand="1">'
            // BST FIRST (EndorsingSupportingTokens / AlwaysToRecipient)
            .       '<o:BinarySecurityToken'
            .         ' u:Id="'         . $this->bstId          . '"'
            .         ' ValueType="'    . self::X509_VALUETYPE   . '"'
            .         ' EncodingType="' . self::B64_ENCODINGTYPE . '">'
            .         $certB64
            .       '</o:BinarySecurityToken>'
            // Timestamp AFTER BST
            .       '<u:Timestamp u:Id="_ts">'
            .         '<u:Created>' . $now     . '</u:Created>'
            .         '<u:Expires>' . $expires . '</u:Expires>'
            .       '</u:Timestamp>'
            // Signature-ն ՉԿԱ — signEnvelope()-ն կavoid C14N corruption-ը
            .     '</o:Security>'
            .   '</s:Header>'
            .   '<s:Body u:Id="_body">' . $bodyContent . '</s:Body>'
            . '</s:Envelope>';
    }

    // ================================================================
    // Step 2 — Sign
    //
    // Ճիշտ flow:
    //  1. Parse envelope (Signature ՉԿԱ)
    //  2. C14N digest-ներ հաշվել (ts, body, bst, to)
    //  3. SignedInfo build
    //  4. Sign
    //  5. Signature node append Security-ի վերջում
    // ================================================================

    private function signEnvelope(string $envelopeXml): string
    {
        // ── Parse ──────────────────────────────────────────────────────────────
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput       = false;
        $dom->loadXML($envelopeXml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('s',  self::SOAP_NS);
        $xpath->registerNamespace('u',  self::WSU_NS);
        $xpath->registerNamespace('o',  self::WSSE_NS);
        $xpath->registerNamespace('a',  self::WSA_NS);
        $xpath->registerNamespace('ds', self::DSIG_NS);

        // wsu:Id → XML ID
        foreach ($xpath->query('//*[@u:Id]') as $node) {
            $node->setIdAttributeNS(self::WSU_NS, 'Id', true);
        }

        // ── Node-ների ստացում ─────────────────────────────────────────────────
        $tsNode   = $xpath->query('//u:Timestamp[@u:Id="_ts"]')->item(0);
        $bodyNode = $xpath->query('//s:Body[@u:Id="_body"]')->item(0);
        $bstNode  = $xpath->query('//o:BinarySecurityToken[@u:Id="' . $this->bstId . '"]')->item(0);
        $toNode   = $xpath->query('//a:To[@u:Id="_to"]')->item(0);
        $actionNode   = $xpath->query('//a:Action[@u:Id="' . $this->actionId . '"]')->item(0);
        $messageIdNode = $xpath->query('//a:MessageID[@u:Id="' . $this->messageIdId . '"]')->item(0);

        if (!$tsNode || !$bodyNode || !$bstNode || !$toNode || !$actionNode || !$messageIdNode) {
            throw new \RuntimeException('DEGS sign: required node not found (ts/body/bst/to/action/messageId)');
        }

        // ── Digests (Exc-C14N + InclusiveNamespaces, SHA256) ──────────────────
        // Digest MUST match ds:Transform output (InclusiveNamespaces PrefixList)
        $incPrefixes = preg_split('/\s+/', trim(self::INCLUSIVE_PREFIX_LIST));
        $tsDigest   = base64_encode(hash('sha256', $tsNode->C14N(true, false, null, $incPrefixes),   true));
        $bodyDigest = base64_encode(hash('sha256', $bodyNode->C14N(true, false, null, $incPrefixes), true));
        $toDigest   = base64_encode(hash('sha256', $toNode->C14N(true, false, null, $incPrefixes),   true));
        $actionDigest   = base64_encode(hash('sha256', $actionNode->C14N(true, false, null, $incPrefixes),   true));
        $messageIdDigest = base64_encode(hash('sha256', $messageIdNode->C14N(true, false, null, $incPrefixes), true));

        // ── SignedInfo build ──────────────────────────────────────────────────
        $signedInfo = $dom->createElementNS(self::DSIG_NS, 'ds:SignedInfo');

        $canonMethod = $dom->createElementNS(self::DSIG_NS, 'ds:CanonicalizationMethod');
        $canonMethod->setAttribute('Algorithm', self::C14N_ALG);
        $inclusive = $dom->createElementNS(self::EXC14N_NS, 'ec:InclusiveNamespaces');
        $inclusive->setAttribute('PrefixList', self::INCLUSIVE_PREFIX_LIST);
        $canonMethod->appendChild($inclusive);
        $signedInfo->appendChild($canonMethod);

        $sigMethod = $dom->createElementNS(self::DSIG_NS, 'ds:SignatureMethod');
        $sigMethod->setAttribute('Algorithm', self::SIG_ALG);
        $signedInfo->appendChild($sigMethod);

        // Helper — Reference ստեղծել
        $addRef = function (string $uri, string $digest) use ($dom, $signedInfo): void {
            $ref = $dom->createElementNS(self::DSIG_NS, 'ds:Reference');
            $ref->setAttribute('URI', $uri);

            $transforms = $dom->createElementNS(self::DSIG_NS, 'ds:Transforms');
            $transform  = $dom->createElementNS(self::DSIG_NS, 'ds:Transform');
            $transform->setAttribute('Algorithm', self::C14N_ALG);
            $inclusive = $dom->createElementNS(self::EXC14N_NS, 'ec:InclusiveNamespaces');
            $inclusive->setAttribute('PrefixList', self::INCLUSIVE_PREFIX_LIST);
            $transform->appendChild($inclusive);
            $transforms->appendChild($transform);
            $ref->appendChild($transforms);

            $dm = $dom->createElementNS(self::DSIG_NS, 'ds:DigestMethod');
            $dm->setAttribute('Algorithm', self::DIGEST_ALG);
            $ref->appendChild($dm);

            $dv = $dom->createElementNS(self::DSIG_NS, 'ds:DigestValue', $digest);
            $ref->appendChild($dv);

            $signedInfo->appendChild($ref);
        };

        // References: ts, body, to, wsa:Action, wsa:MessageID
        $addRef('#_ts',             $tsDigest);
        $addRef('#_body',           $bodyDigest);
        $addRef('#_to',             $toDigest);
        $addRef('#' . $this->actionId, $actionDigest);
        $addRef('#' . $this->messageIdId, $messageIdDigest);

        // ── SignedInfo C14N ───────────────────────────────────────────────────
        $siDom = new DOMDocument();
        $siDom->preserveWhiteSpace = false;
        $siDom->appendChild($siDom->importNode($signedInfo, true));
        $signedInfoC14n = $siDom->documentElement->C14N(true, false, null, $incPrefixes);

        // ── Sign ─────────────────────────────────────────────────────────────
        $privateKey = openssl_pkey_get_private('file://' . $this->keyPath, $this->certPassword);
        if (!$privateKey) {
            throw new \RuntimeException('DEGS: Private key load error: ' . openssl_error_string()
                . ' (check CREDIT_REGISTRY_CLIENT_KEY_PATH and CREDIT_REGISTRY_CLIENT_CERT_PASSWORD)');
        }
        if (!openssl_sign($signedInfoC14n, $rawSig, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('DEGS: Sign error: ' . openssl_error_string());
        }

        // ── Signature node build ──────────────────────────────────────────────
        $signatureNode = $dom->createElementNS(self::DSIG_NS, 'ds:Signature');

        // SignedInfo
        $signatureNode->appendChild($signedInfo);

        // SignatureValue
        $signatureNode->appendChild(
            $dom->createElementNS(self::DSIG_NS, 'ds:SignatureValue', base64_encode($rawSig))
        );

        // KeyInfo — ThumbprintSHA1 reference (required by sp:RequireThumbprintReference)
        $certPem    = file_get_contents($this->certPath);
        $certDer    = base64_decode(str_replace(["\r", "\n", " "], '', preg_replace('/-----[^-]+-----/', '', $certPem)));
        $thumbprint = base64_encode(sha1($certDer, true));
        $keyInfo = $dom->createElementNS(self::DSIG_NS, 'ds:KeyInfo');
        $str     = $dom->createElementNS(self::WSSE_NS, 'o:SecurityTokenReference');
        $ki      = $dom->createElementNS(self::WSSE_NS, 'o:KeyIdentifier', $thumbprint);
        $ki->setAttribute('ValueType', self::THUMB_VALUETYPE);
        $ki->setAttribute('EncodingType', self::B64_ENCODINGTYPE);
        $str->appendChild($ki);
        $keyInfo->appendChild($str);
        $signatureNode->appendChild($keyInfo);

        // ── Signature-ն Security-ի վերջում append ────────────────────────────
        $secNode = $xpath->query('//o:Security')->item(0);
        if (!$secNode) {
            throw new \RuntimeException('DEGS: Security node not found');
        }
        $secNode->appendChild($signatureNode);

        return $dom->saveXML();
    }

    // ================================================================
    // Step 3 — cURL (HTTPS + mTLS)
    // ================================================================

    private function sendViaCurl(string $action, string $xml): string
    {
        $actionUrl = self::ACTION_NS . $action;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->endpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/soap+xml; charset=utf-8; action="' . $actionUrl . '"',
            ],
            CURLOPT_SSLCERT        => $this->certPath,
            CURLOPT_SSLCERTTYPE    => 'PEM',
            CURLOPT_SSLKEY         => $this->keyPath,
            CURLOPT_SSLKEYTYPE     => 'PEM',
            CURLOPT_KEYPASSWD      => $this->certPassword,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_CAINFO         => $this->caPath ?: null,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException('DEGS cURL error: ' . $curlErr);
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException(
                'DEGS HTTP ' . $httpCode . ': ' . $this->extractFault((string) $response)
            );
        }

        return (string) $response;
    }

    // ================================================================
    // Helpers
    // ================================================================

    private function extractSendRequestResult(string $xml): int
    {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml)) {
            throw new \RuntimeException('DEGS: Invalid XML response: ' . substr($xml, 0, 300));
        }
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[local-name()="SendRequestResult"]');
        if ($nodes->length === 0) {
            throw new \RuntimeException(
                'DEGS: SendRequestResult not found. Response: ' . substr($xml, 0, 500)
            );
        }
        return (int) $nodes->item(0)->textContent;
    }

    private function extractFault(string $xml): string
    {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml)) {
            return substr($xml, 0, 500);
        }
        $xpath = new DOMXPath($dom);
        $parts = [];
        foreach ($xpath->query('//*[local-name()="Text"]')   as $n) { $parts[] = trim($n->textContent); }
        foreach ($xpath->query('//*[local-name()="Value"]')  as $n) { $parts[] = trim($n->textContent); }
        foreach ($xpath->query('//*[local-name()="Detail"]') as $n) { $parts[] = trim($n->textContent); }
        return $parts
            ? implode(' | ', array_unique(array_filter($parts)))
            : substr($xml, 0, 500);
    }

    private function uuid4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}
