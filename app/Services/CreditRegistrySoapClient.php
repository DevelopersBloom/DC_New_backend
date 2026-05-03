<?php

namespace App\Services;

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use DOMDocument;
use DOMXPath;

class CreditRegistrySoapClient
{
    private const ENDPOINT  = 'https://100.100.100.60:8888/DEGSHost';
    private const ACTION_NS = 'http://tempuri.org/IDegsNSS/';
    private const CERT_PATH = '/etc/ssl/degs/client.crt';
    private const KEY_PATH  = '/etc/ssl/degs/client.key';
    private const CA_PATH   = '/etc/ssl/certs/DEGSTESTRootCA.pem';
    private const APP_NAME  = 'ACREDIT';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

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
        $bodyContent = '<tns:IsResponsePrepared xmlns:tns="http://tempuri.org/"><tns:requsetId>'
            . $requestId
            . '</tns:requsetId></tns:IsResponsePrepared>';

        $response = $this->dispatch('IsResponsePrepared', $bodyContent);
        $dom      = new DOMDocument();
        $dom->loadXML($response);
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[local-name()="IsResponsePreparedResult"]');

        return $nodes->length > 0 && strtolower(trim($nodes->item(0)->textContent)) === 'true';
    }

    public function getResponse(int $requestId): string
    {
        $bodyContent = '<tns:GetResponse xmlns:tns="http://tempuri.org/"><tns:requsetId>'
            . $requestId
            . '</tns:requsetId></tns:GetResponse>';

        return $this->dispatch('GetResponse', $bodyContent);
    }

    public function isAlive(): bool
    {
        try {
            $bodyContent = '<tns:IsAlive xmlns:tns="http://tempuri.org/"/>';
            $response    = $this->dispatch('IsAlive', $bodyContent);
            $dom         = new DOMDocument();
            $dom->loadXML($response);
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query('//*[local-name()="IsAliveResult"]');

            return $nodes->length > 0 && strtolower(trim($nodes->item(0)->textContent)) === 'true';
        } catch (\Throwable $e) {
            \Log::error('DEGS isAlive failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Internal — SendRequest wrapper
    // -------------------------------------------------------------------------

    // ... ներսում փոխեք հետևյալ մեթոդը
    private function sendRequest(string $docType, string $xmlContent, bool $dryRun): int
    {
        $bodyContent = '<tns:SendRequest xmlns:tns="http://tempuri.org/">'
            . '<tns:AppName>' . self::APP_NAME . '</tns:AppName>'
            . '<tns:DocType>' . $docType . '</tns:DocType>'
            . '<tns:IsDelay>false</tns:IsDelay>'
            . '<tns:xml>' . $xmlContent . '</tns:xml>'
            . '</tns:SendRequest>';

        $response = $this->dispatch('SendRequest', $bodyContent);

        return $this->extractSendRequestResult($response);
    }

    // -------------------------------------------------------------------------
    // Internal — build → sign → send
    // -------------------------------------------------------------------------

    private function dispatch(string $action, string $bodyContent): string
    {
        $envelope  = $this->buildEnvelope($action, $bodyContent);
        $signed    = $this->signEnvelope($envelope);
        return $this->sendViaCurl($action, $signed);
    }

    // -------------------------------------------------------------------------
    // Step 1 — Build SOAP envelope
    // -------------------------------------------------------------------------

    private function buildEnvelope(string $action, string $bodyContent): string
    {
        $actionUrl = self::ACTION_NS . $action;
        $endpoint  = self::ENDPOINT;
        $msgId     = 'urn:uuid:' . $this->uuid();
        $now       = gmdate('Y-m-d\TH:i:s\Z');
        $expires   = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

        // Certificate — base64 DER for BinarySecurityToken
        $certPem = file_get_contents(self::CERT_PATH);
        $certDer = base64_decode(str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r", ' '],
            '',
            $certPem
        ));
        $certB64     = base64_encode($certDer);
        $this->bstId = 'bst-' . $this->uuid(); // save for signEnvelope

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<s:Envelope'
            .     ' xmlns:s="http://www.w3.org/2003/05/soap-envelope"'
            .     ' xmlns:a="http://www.w3.org/2005/08/addressing"'
            .     ' xmlns:u="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">'
            .   '<s:Header>'
            .     '<a:Action s:mustUnderstand="1">' . $actionUrl . '</a:Action>'
            .     '<a:MessageID>' . $msgId . '</a:MessageID>'
            .     '<a:To s:mustUnderstand="1" u:Id="_to">' . $endpoint . '</a:To>'
            .     '<o:Security'
            .         ' s:mustUnderstand="1"'
            .         ' xmlns:o="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
            .       '<u:Timestamp u:Id="_ts">'
            .         '<u:Created>' . $now . '</u:Created>'
            .         '<u:Expires>' . $expires . '</u:Expires>'
            .       '</u:Timestamp>'
            .       '<o:BinarySecurityToken'
            .           ' u:Id="' . $this->bstId . '"'
            .           ' ValueType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3"'
            .           ' EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">'
            .         $certB64
            .       '</o:BinarySecurityToken>'
            .     '</o:Security>'
            .   '</s:Header>'
            .   '<s:Body u:Id="_body">'
            .     $bodyContent
            .   '</s:Body>'
            . '</s:Envelope>';
    }

    // -------------------------------------------------------------------------
    // Step 2 — Sign envelope (WS-Security X.509, SHA-256)
    // -------------------------------------------------------------------------

    private string $bstId = '';

    private function signEnvelope(string $envelopeXml): string
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($envelopeXml);

        $dsig = new XMLSecurityDSig('');
        // ԿԱՐԵՎՈՐ: Օգտագործում ենք EXC_C14N
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

        $transforms = [XMLSecurityDSig::EXC_C14N];

        // Ստորագրում ենք ID-ներով նշված հատվածները
        $dsig->addReference($dom, XMLSecurityDSig::SHA256, $transforms, ['uri' => '#_ts']);
        $dsig->addReference($dom, XMLSecurityDSig::SHA256, $transforms, ['uri' => '#_to']);
        $dsig->addReference($dom, XMLSecurityDSig::SHA256, $transforms, ['uri' => '#_body']);

        $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $objKey->loadKey(self::KEY_PATH, true);

        // Ստորագրում ենք
        $dsig->sign($objKey);

        // Գտնում ենք Security տեգը
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('o', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd');
        $secNode = $xpath->query('//o:Security')->item(0);

        if (!$secNode) {
            throw new \RuntimeException("Security node not found in envelope");
        }

        $dsig->appendSignature($secNode);

        // Ավելացնում ենք KeyInfo-ն, որը հղվում է մեր BinarySecurityToken-ին
        $sigNode = $secNode->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'Signature')->item(0);
        $keyInfo = $dom->createElementNS(XMLSecurityDSig::XMLDSIGNS, 'ds:KeyInfo');
        $str = $dom->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'o:SecurityTokenReference');
        $ref = $dom->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'o:Reference');
        $ref->setAttribute('URI', '#' . $this->bstId);
        $ref->setAttribute('ValueType', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3');

        $str->appendChild($ref);
        $keyInfo->appendChild($str);
        $sigNode->appendChild($keyInfo);

        return $dom->saveXML();
    }
    // -------------------------------------------------------------------------

    private function sendViaCurl(string $action, string $signedXml): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $signedXml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/soap+xml; charset=utf-8',
                'SOAPAction: "' . self::ACTION_NS . $action . '"',
            ],
            // mTLS — client certificate
            CURLOPT_SSLCERT        => self::CERT_PATH,
            CURLOPT_SSLKEY         => self::KEY_PATH,
            // CA verification — server cert-ը ստուգել
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO         => self::CA_PATH,
            // 0 = IP-ով կապվելիս hostname match չի ստուգվի
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
                'DEGS SOAP error HTTP ' . $httpCode . ': ' . $this->extractFault($response)
            );
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function extractSendRequestResult(string $xml): int
    {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml)) {
            throw new \RuntimeException('DEGS: Invalid XML response: ' . substr($xml, 0, 300));
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[local-name()="SendRequestResult"]');

        if ($nodes->length === 0) {
            throw new \RuntimeException('DEGS: SendRequestResult not found. Response: ' . substr($xml, 0, 500));
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

        foreach ($xpath->query('//*[local-name()="Text"]') as $node) {
            $parts[] = $node->textContent;
        }
        foreach ($xpath->query('//*[local-name()="Value"]') as $node) {
            $parts[] = $node->textContent;
        }

        return $parts ? implode(' | ', array_unique($parts)) : substr($xml, 0, 500);
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
