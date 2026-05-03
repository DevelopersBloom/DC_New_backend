<?php

namespace App\Services;

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use DOMDocument;
use DOMXPath;

class CreditRegistrySoapClient
{
    // ----------------------------------------------------------------
    // Configuration
    // ----------------------------------------------------------------
    private const ENDPOINT  = 'https://100.100.100.60:8888/DEGSHost';
    private const ACTION_NS = 'http://tempuri.org/IDegsNSS/';
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
        $body = '<tns:IsResponsePrepared xmlns:tns="http://tempuri.org/">'
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
        // CDATA-ի փոխարեն htmlspecialchars — WCF-ը CDATA-ն ճիշտ չի parse անում
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
        $this->bstId = 'bst-' . $this->uuid4();

        // Մաքուր DER certificate
        $rawPem = file_get_contents(self::CERT_PATH);
        openssl_x509_export(openssl_x509_read($rawPem), $cleanPem);
        $certDer = base64_decode(
            preg_replace('/-----[^-]+-----|[\r\n\s]/', '', $cleanPem)
        );
        $certB64 = base64_encode($certDer);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<s:Envelope'
            .   ' xmlns:s="'  . self::SOAP_NS . '"'
            .   ' xmlns:a="'  . self::WSA_NS  . '"'
            .   ' xmlns:u="'  . self::WSU_NS  . '"'
            .   ' xmlns:o="'  . self::WSSE_NS . '">'
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

    // ================================================================
    // Step 2 — WS-Security XML Signature (RSA-SHA256 + EXC-C14N)
    // ================================================================

    private function signEnvelope(string $envelopeXml): string
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($envelopeXml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('u', self::WSU_NS);
        $xpath->registerNamespace('o', self::WSSE_NS);
        $xpath->registerNamespace('s', self::SOAP_NS);

        // 1. Ստեղծում ենք ստորագրող օբյեկտը
        $dsig = new XMLSecurityDSig('');
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

        // 2. Մանուալ ավելացնում ենք Reference-ները՝ օգտագործելով XPath
        $targets = [
            '_ts'   => '//u:Timestamp[@u:Id="_ts"]',
            '_body' => '//s:Body[@u:Id="_body"]',
        ];

        foreach ($targets as $id => $query) {
            $node = $xpath->query($query)->item(0);
            if (!$node) {
                throw new \RuntimeException("DEGS: Node with Id $id not found via XPath.");
            }

            // Կանոնիկացնում ենք node-ը (Exclusive C14N)
            $canonicalNode = $node->C14N(true, false);
            // Հաշվում ենք SHA256 digest-ը
            $digestValue = base64_encode(hash('sha256', $canonicalNode, true));

            // Ավելացնում ենք Reference-ը xmlseclibs-ին
            $dsig->addReference(
                $dom,
                XMLSecurityDSig::SHA256,
                [XMLSecurityDSig::EXC_C14N],
                [
                    'uri' => '#' . $id,
                    'force_uri' => true,
                    'digest_value' => $digestValue // Փոխանցում ենք մեր հաշվարկածը
                ]
            );
        }

        // 3. Ստորագրում ենք Private Key-ով
        $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $objKey->loadKey(self::KEY_PATH, true);
        $dsig->sign($objKey);

        // 4. Կցում ենք ստորագրությունը Security տեգին
        $secNode = $xpath->query('//o:Security')->item(0);
        $dsig->appendSignature($secNode);

        // 5. Կարգավորում ենք KeyInfo-ն BST Reference-ի համար
        $sigNode = $secNode->getElementsByTagNameNS(self::DSIG_NS, 'Signature')->item(0);
        $oldKI = $sigNode->getElementsByTagNameNS(self::DSIG_NS, 'KeyInfo')->item(0);
        if ($oldKI) { $sigNode->removeChild($oldKI); }

        $keyInfo = $dom->createElementNS(self::DSIG_NS, 'ds:KeyInfo');
        $str = $dom->createElementNS(self::WSSE_NS, 'o:SecurityTokenReference');
        $ref = $dom->createElementNS(self::WSSE_NS, 'o:Reference');
        $ref->setAttribute('URI', '#' . $this->bstId);
        $ref->setAttribute('ValueType', self::X509_VALUETYPE);

        $str->appendChild($ref);
        $keyInfo->appendChild($str);
        $sigNode->appendChild($keyInfo);

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
            CURLOPT_URL            => self::ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                // SOAP 1.2 — action Content-Type-ի մեջ (ոչ SOAPAction header)
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
