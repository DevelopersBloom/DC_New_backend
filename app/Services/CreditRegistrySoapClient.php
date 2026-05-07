<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;

class CreditRegistrySoapClient
{
    private const ENDPOINT  = 'https://100.100.100.60:8888/DEGSHost';
    private const ACTION_NS = 'http://tempuri.org/';

    private const APP_NAME  = 'ACREDIT';
    private const CERT_PATH = '/etc/ssl/degs/client.crt';
    private const KEY_PATH  = '/etc/ssl/degs/client.key';
    private const CA_PATH   = '/etc/ssl/certs/DEGSTESTRootCA.pem';

    private const WSU_NS  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    private const WSSE_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    private const WSA_NS  = 'http://www.w3.org/2005/08/addressing';
    private const SOAP_NS = 'http://www.w3.org/2003/05/soap-envelope';
    private const DSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';

    private const X509_VALUETYPE   = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
    private const B64_ENCODINGTYPE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

    private string $bstId = '';

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
            . '<tns:AppName>' . self::APP_NAME . '</tns:AppName>'
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
    // ================================================================

    private function buildEnvelope(string $action, string $bodyContent): string
    {
        $actionUrl   = self::ACTION_NS . $action;
        $msgId       = 'urn:uuid:' . $this->uuid4();
        $now         = gmdate('Y-m-d\TH:i:s\Z');
        $expires     = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
        $this->bstId = 'X509-' . $this->uuid4();

        $rawPem  = file_get_contents(self::CERT_PATH);
        $pemClean = preg_replace('/-----[^-]+-----|[\r\n]/', '', $rawPem);
        $certB64 = str_replace(["\r", "\n", " "], '', preg_replace('/-----[^-]+-----/', '', $rawPem));

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<s:Envelope'
            .   ' xmlns:s="' . self::SOAP_NS  . '"'
            .   ' xmlns:a="' . self::WSA_NS   . '"'
            .   ' xmlns:u="' . self::WSU_NS   . '"'
            .   ' xmlns:o="' . self::WSSE_NS  . '"'
            .   ' xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'
            .   '<s:Header>'
            .     '<a:Action s:mustUnderstand="1">' . $actionUrl . '</a:Action>'
            .     '<a:MessageID>' . $msgId . '</a:MessageID>'
            .     '<a:To s:mustUnderstand="1">' . self::ENDPOINT . '</a:To>'
            .     '<o:Security s:mustUnderstand="1">'
            .       '<u:Timestamp u:Id="_ts">'
            .         '<u:Created>' . $now     . '</u:Created>'
            .         '<u:Expires>' . $expires . '</u:Expires>'
            .       '</u:Timestamp>'
            .       '<o:BinarySecurityToken'
            .         ' u:Id="'         . $this->bstId          . '"'
            .         ' ValueType="'    . self::X509_VALUETYPE   . '"'
            .         ' EncodingType="' . self::B64_ENCODINGTYPE . '">'
            .         $certB64
            .       '</o:BinarySecurityToken>'
            .     '</o:Security>'
            .   '</s:Header>'
            .   '<s:Body u:Id="_body">' . $bodyContent . '</s:Body>'
            . '</s:Envelope>';
    }
    // namespace helper
    private function ns(string $const): string
    {
        return constant('self::' . $const);
    }

//    private function signEnvelope(string $envelopeXml): string
//    {
//        $dom = new DOMDocument();
//        $dom->preserveWhiteSpace = false;
//        $dom->formatOutput       = false;
//        $dom->loadXML($envelopeXml);
//
//        $xpath = new DOMXPath($dom);
//        $xpath->registerNamespace('s', self::SOAP_NS);
//        $xpath->registerNamespace('u', self::WSU_NS);
//        $xpath->registerNamespace('o', self::WSSE_NS);
//        $xpath->registerNamespace('ds', self::DSIG_NS);
//
//        foreach ($xpath->query('//*[@u:Id]') as $node) {
//            $node->setIdAttributeNS(self::WSU_NS, 'Id', true);
//        }
//
//        $tsNode   = $xpath->query('//u:Timestamp[@u:Id="_ts"]')->item(0);
//        $bodyNode = $xpath->query('//s:Body[@u:Id="_body"]')->item(0);
//        // ✅ FIX 1 — BST node-ը գտնել
//        $bstNode  = $xpath->query('//o:BinarySecurityToken[@u:Id="' . $this->bstId . '"]')->item(0);
//
//        if (!$tsNode || !$bodyNode || !$bstNode) {
//            throw new \RuntimeException('DEGS sign: ts/body/bst node not found');
//        }
//
//        $tsDigest   = base64_encode(hash('sha256', $tsNode->C14N(true, false),   true));
//        $bodyDigest = base64_encode(hash('sha256', $bodyNode->C14N(true, false), true));
//        // ✅ FIX 1 — BST digest
//        $bstDigest  = base64_encode(hash('sha256', $bstNode->C14N(true, false),  true));
//
//        // ✅ FIX 1 — 3 Reference (ts + body + bst)
//        $signedInfoXml = '<ds:SignedInfo'
//            . ' xmlns:ds="' . self::DSIG_NS . '"'
//            . ' xmlns:s="'  . self::SOAP_NS . '"'
//            . ' xmlns:a="'  . self::WSA_NS  . '"'
//            . ' xmlns:u="'  . self::WSU_NS  . '"'
//            . ' xmlns:o="'  . self::WSSE_NS . '">'
//            . '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#">'
//            .   '<ec:InclusiveNamespaces'
//            .     ' xmlns:ec="http://www.w3.org/2001/10/xml-exc-c14n#"'
//            .     ' PrefixList=""/>'
//            . '</ds:CanonicalizationMethod>'
//            . '<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>'
//            // Timestamp
//            . '<ds:Reference URI="#_ts">'
//            .   '<ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms>'
//            .   '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
//            .   '<ds:DigestValue>' . $tsDigest . '</ds:DigestValue>'
//            . '</ds:Reference>'
//            // Body
//            . '<ds:Reference URI="#_body">'
//            .   '<ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms>'
//            .   '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
//            .   '<ds:DigestValue>' . $bodyDigest . '</ds:DigestValue>'
//            . '</ds:Reference>'
//            // BST — WCF-ը պահանջում է
//            . '<ds:Reference URI="#' . $this->bstId . '">'
//            .   '<ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms>'
//            .   '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
//            .   '<ds:DigestValue>' . $bstDigest . '</ds:DigestValue>'
//            . '</ds:Reference>'
//            . '</ds:SignedInfo>';
//
//        $siDom = new DOMDocument();
//        $siDom->preserveWhiteSpace = false;
//        $siDom->loadXML($signedInfoXml);
//        $signedInfoC14n = $siDom->documentElement->C14N(true, false);
//
//        $privateKey = openssl_pkey_get_private('file://' . self::KEY_PATH);
//        if (!$privateKey) {
//            throw new \RuntimeException('DEGS: Private key load error: ' . openssl_error_string());
//        }
//        if (!openssl_sign($signedInfoC14n, $rawSig, $privateKey, OPENSSL_ALGO_SHA256)) {
//            throw new \RuntimeException('DEGS: Sign error: ' . openssl_error_string());
//        }
//        $signatureValue = base64_encode($rawSig);
//
//        $secNode = $xpath->query('//o:Security')->item(0);
//        if (!$secNode) {
//            throw new \RuntimeException('DEGS: Security node not found');
//        }
//
//        $dsigNs = self::DSIG_NS;
//        $wsseNs = self::WSSE_NS;
//
//        $sigNode = $dom->createElement('ds:Signature');
//        $secNode->appendChild($sigNode);
//
//        $siDom2 = new DOMDocument();
//        $siDom2->loadXML($signedInfoXml);
//        $sigNode->appendChild($dom->importNode($siDom2->documentElement, true));
//
//        $sigValNode = $dom->createElementNS($dsigNs, 'ds:SignatureValue', $signatureValue);
//        $sigNode->appendChild($sigValNode);
//
//        // ✅ FIX 2 — namespace redeclaration-ից խուսափել
//        // createElement (առանց NS) — prefix-ը parent-ից inherit կանի
//        $keyInfoNode = $dom->createElementNS($dsigNs, 'ds:KeyInfo');
//        $strNode     = $dom->createElement('o:SecurityTokenReference');
//        $refNode     = $dom->createElement('o:Reference');
//        $refNode->setAttribute('URI',       '#' . $this->bstId);
//        $refNode->setAttribute('ValueType', self::X509_VALUETYPE);
//
//        $strNode->appendChild($refNode);
//        $keyInfoNode->appendChild($strNode);
//        $sigNode->appendChild($keyInfoNode);
//
//        return $dom->saveXML();
//    }    // Step 3 — cURL (HTTPS + mTLS)

    private function signEnvelope(string $xml): string
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);

        $xpath->registerNamespace('s', self::SOAP_NS);
        $xpath->registerNamespace('u', self::WSU_NS);
        $xpath->registerNamespace('o', self::WSSE_NS);
        $xpath->registerNamespace('ds', self::DSIG_NS);

        // make wsu:Id act as ID
        foreach ($xpath->query('//*[@u:Id]') as $node) {
            $node->setIdAttributeNS(self::WSU_NS, 'Id', true);
        }

        $tsNode   = $xpath->query('//u:Timestamp[@u:Id="_ts"]')->item(0);
        $bodyNode = $xpath->query('//s:Body[@u:Id="_body"]')->item(0);
        $toNode   = $xpath->query('//a:To')->item(0);

        if (!$tsNode || !$bodyNode || !$toNode) {
            throw new \RuntimeException('Missing TS / Body / To');
        }

        // ✅ IMPORTANT: WCF expects SHA1 (ոչ SHA256!)
        $tsDigest   = base64_encode(hash('sha1', $tsNode->C14N(true, false), true));
        $bodyDigest = base64_encode(hash('sha1', $bodyNode->C14N(true, false), true));
        $toDigest   = base64_encode(hash('sha1', $toNode->C14N(true, false), true));

        $securityNode = $xpath->query('//o:Security')->item(0);

        if (!$securityNode) {
            throw new \RuntimeException('Security missing');
        }

        $signatureNode = $dom->createElementNS(self::DSIG_NS, 'ds:Signature');
        $securityNode->appendChild($signatureNode);

        $signedInfo = $dom->createElementNS(self::DSIG_NS, 'ds:SignedInfo');
        $signatureNode->appendChild($signedInfo);

        // Canonicalization
        $canon = $dom->createElementNS(self::DSIG_NS, 'ds:CanonicalizationMethod');
        $canon->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
        $signedInfo->appendChild($canon);

        // ⚠️ WCF usually expects RSA-SHA1
        $sigMethod = $dom->createElementNS(self::DSIG_NS, 'ds:SignatureMethod');
        $sigMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1');
        $signedInfo->appendChild($sigMethod);

        $addRef = function ($uri, $digest) use ($dom, $signedInfo) {

            $ref = $dom->createElementNS(self::DSIG_NS, 'ds:Reference');
            $ref->setAttribute('URI', $uri);

            $trans = $dom->createElementNS(self::DSIG_NS, 'ds:Transforms');
            $t = $dom->createElementNS(self::DSIG_NS, 'ds:Transform');
            $t->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
            $trans->appendChild($t);

            $dm = $dom->createElementNS(self::DSIG_NS, 'ds:DigestMethod');
            $dm->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');

            $dv = $dom->createElementNS(self::DSIG_NS, 'ds:DigestValue', $digest);

            $ref->appendChild($trans);
            $ref->appendChild($dm);
            $ref->appendChild($dv);

            $signedInfo->appendChild($ref);
        };

        $addRef('#_ts', $tsDigest);
        $addRef('#_body', $bodyDigest);
        $addRef('#_to', $toDigest); // ✅ REQUIRED (քո XML-ում կա)

        $signedInfoC14n = $signedInfo->C14N(true, false);

        $privateKey = openssl_pkey_get_private('file://' . self::KEY_PATH);

        if (!$privateKey) {
            throw new \RuntimeException('Private key error');
        }

        openssl_sign($signedInfoC14n, $signatureRaw, $privateKey, OPENSSL_ALGO_SHA1);

        $signatureNode->appendChild(
            $dom->createElementNS(self::DSIG_NS, 'ds:SignatureValue', base64_encode($signatureRaw))
        );

        // =========================================================
        // ✅ KEY FIX — Thumbprint (ինչ որ սերվերը պահանջում է)
        // =========================================================

        $cert = file_get_contents(self::CERT_PATH);
        $cert = str_replace(
            ["-----BEGIN CERTIFICATE-----", "-----END CERTIFICATE-----", "\n", "\r", " "],
            '',
            $cert
        );

        $der = base64_decode($cert);
        $thumbprint = base64_encode(sha1($der, true));

        $keyInfo = $dom->createElementNS(self::DSIG_NS, 'ds:KeyInfo');
        $secRef  = $dom->createElementNS(self::WSSE_NS, 'o:SecurityTokenReference');

        $keyId = $dom->createElementNS(
            self::WSSE_NS,
            'wsse:KeyIdentifier',
            $thumbprint
        );

        $keyId->setAttribute(
            'ValueType',
            'http://docs.oasis-open.org/wss/oasis-wss-soap-message-security-1.1#ThumbprintSHA1'
        );

        $keyId->setAttribute(
            'EncodingType',
            self::B64_ENCODINGTYPE
        );

        $secRef->appendChild($keyId);
        $keyInfo->appendChild($secRef);
        $signatureNode->appendChild($keyInfo);

        return $dom->saveXML();
    }
    private function sendViaCurl(string $action, string $xml): string
    {
        $actionUrl = self::ACTION_NS . $action;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/soap+xml; charset=utf-8; action="' . $actionUrl . '"',
            ],
            CURLOPT_SSLCERT        => self::CERT_PATH,
            CURLOPT_SSLKEY         => self::KEY_PATH,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO         => self::CA_PATH,
            CURLOPT_SSL_VERIFYHOST => 0,
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
