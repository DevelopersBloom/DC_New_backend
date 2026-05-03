<?php

namespace App\Services;

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use DOMDocument;
use DOMXPath;

/**
 * DEGS WEB-ծառայության SOAP կլիենտ
 *
 * Transport  : HTTPS + mTLS (client certificate)
 * Security   : WS-Security X.509 + XML DSig (RSA-SHA256, EXC-C14N)
 * Protocol   : SOAP 1.2
 * Signed refs: Timestamp (#_ts) + Body (#_body)
 *              (To (#_to) — հեռացված, DEGS-ը հաճախ մերժում է)
 */
class CreditRegistrySoapClient
{
    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    private const ENDPOINT   = 'https://100.100.100.60:8888/DEGSHost';
    private const ACTION_NS  = 'http://tempuri.org/IDegsNSS/';
    private const APP_NAME   = 'ACREDIT';

    private const CERT_PATH  = '/etc/ssl/degs/client.crt';
    private const KEY_PATH   = '/etc/ssl/degs/client.key';
    private const CA_PATH    = '/etc/ssl/certs/DEGSTESTRootCA.pem';

    // WS-Security namespaces
    private const WSU_NS  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    private const WSSE_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    private const WSA_NS  = 'http://www.w3.org/2005/08/addressing';
    private const SOAP_NS = 'http://www.w3.org/2003/05/soap-envelope';

    // X.509 ValueType
    private const X509_VALUETYPE   = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
    private const B64_ENCODINGTYPE  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

    /** BinarySecurityToken ID — ամեն request-ի համար նոր */
    private string $bstId = '';

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Ուղարկել L001 (նոր վարկ)
     * @return int  DEGS request ID (polling-ի համար)
     */
    public function sendL001(string $xmlContent, bool $dryRun = false): int
    {
        return $this->sendRequest('L001', $xmlContent, $dryRun);
    }

    /**
     * Ուղարկել L002 (փոփոխություն)
     */
    public function sendL002(string $xmlContent, bool $dryRun = false): int
    {
        return $this->sendRequest('L002', $xmlContent, $dryRun);
    }

    /**
     * Ուղարկել L003 (հեռացում)
     */
    public function sendL003(string $xmlContent, bool $dryRun = false): int
    {
        return $this->sendRequest('L003', $xmlContent, $dryRun);
    }

    /**
     * Ստուգել՝ արդյոք պատասխանը պատրաստ է
     */
    public function isResponsePrepared(int $requestId): bool
    {
        $body = '<tns:IsResponsePrepared xmlns:tns="http://tempuri.org/">'
            . '<tns:requsetId>' . $requestId . '</tns:requsetId>'
            . '</tns:IsResponsePrepared>';

        $response = $this->dispatch('IsResponsePrepared', $body);

        $dom   = new DOMDocument();
        $dom->loadXML($response);
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[local-name()="IsResponsePreparedResult"]');

        return $nodes->length > 0
            && strtolower(trim($nodes->item(0)->textContent)) === 'true';
    }

    /**
     * Ստանալ DEGS-ի պատասխանը (Answer XML)
     */
    public function getResponse(int $requestId): string
    {
        $body = '<tns:GetResponse xmlns:tns="http://tempuri.org/">'
            . '<tns:requsetId>' . $requestId . '</tns:requsetId>'
            . '</tns:GetResponse>';

        return $this->dispatch('GetResponse', $body);
    }

    /**
     * Ping — ծառայությունը հասանե՞լի է
     */
    public function isAlive(): bool
    {
        try {
            $envelope = '<s:Envelope'
                . ' xmlns:s="' . self::SOAP_NS . '"'
                . ' xmlns:a="' . self::WSA_NS . '">'
                . '<s:Header>'
                . '<a:Action>' . self::ACTION_NS . 'IsAlive</a:Action>'
                . '<a:To>' . self::ENDPOINT . '</a:To>'
                . '</s:Header>'
                . '<s:Body><IsAlive xmlns="http://tempuri.org/"/></s:Body>'
                . '</s:Envelope>';

            $response = $this->sendViaCurl('IsAlive', $envelope);
            return str_contains($response, 'true');
        } catch (\Throwable) {
            return false;
        }
    }

    // =========================================================================
    // Internal — SendRequest
    // =========================================================================

    private function sendRequest(string $docType, string $xmlContent, bool $dryRun): int
    {
        // xmlContent-ը wrap ենք CDATA-ով, որ nested XML-ը չփչացնի envelope-ը
        $body = '<tns:SendRequest xmlns:tns="http://tempuri.org/">'
            . '<tns:AppName>' . self::APP_NAME . '</tns:AppName>'
            . '<tns:DocType>' . $docType . '</tns:DocType>'
            . '<tns:IsDelay>' . ($dryRun ? 'true' : 'false') . '</tns:IsDelay>'
            . '<tns:xml><![CDATA[' . $xmlContent . ']]></tns:xml>'
            . '</tns:SendRequest>';

        $response = $this->dispatch('SendRequest', $body);

        return $this->extractSendRequestResult($response);
    }

    // =========================================================================
    // Internal — Build → Sign → Send
    // =========================================================================

    private function dispatch(string $action, string $bodyContent): string
    {
        $envelope = $this->buildEnvelope($action, $bodyContent);
        $signed   = $this->signEnvelope($envelope);
        return $this->sendViaCurl($action, $signed);
    }

    // =========================================================================
    // Step 1 — SOAP Envelope
    // =========================================================================

    private function buildEnvelope(string $action, string $bodyContent): string
    {
        $actionUrl = self::ACTION_NS . $action;
        $msgId     = 'urn:uuid:' . $this->uuid4();
        $now       = gmdate('Y-m-d\TH:i:s\Z');
        $expires   = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
        $this->bstId = 'bst-' . $this->uuid4();

        // Certificate-ի base64 (DER, header/footer հեռ.)
        $certPem = file_get_contents(self::CERT_PATH);
        $certDer = base64_decode(
            str_replace(
                ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r", ' '],
                '',
                $certPem
            )
        );
        $certB64 = base64_encode($certDer);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<s:Envelope'
            .   ' xmlns:s="'   . self::SOAP_NS . '"'
            .   ' xmlns:a="'   . self::WSA_NS  . '"'
            .   ' xmlns:u="'   . self::WSU_NS  . '"'
            .   ' xmlns:o="'   . self::WSSE_NS . '">'

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
            .         ' u:Id="'           . $this->bstId        . '"'
            .         ' ValueType="'      . self::X509_VALUETYPE  . '"'
            .         ' EncodingType="'   . self::B64_ENCODINGTYPE . '">'
            .         $certB64
            .       '</o:BinarySecurityToken>'

            .     '</o:Security>'

            .   '</s:Header>'

            .   '<s:Body u:Id="_body">' . $bodyContent . '</s:Body>'

            . '</s:Envelope>';
    }

    // =========================================================================
    // Step 2 — WS-Security XML Signature (RSA-SHA256, EXC-C14N)
    // =========================================================================

    private function signEnvelope(string $envelopeXml): string
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($envelopeXml);

        $dsig = new XMLSecurityDSig('');
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

        // Ստորագրվող references:
        //   #_ts   — WS-Security Timestamp
        //   #_body — SOAP Body
        // Նշում: #_to-ն (a:To) ՀԵՌԱՑՎԱԾ — DEGS-ը հաճախ մերժում է ստորագրված To-ն
        $refOptions = ['uri' => '#_ts'];
        $dsig->addReference(
            $dom,
            XMLSecurityDSig::SHA256,
            [XMLSecurityDSig::EXC_C14N],
            $refOptions
        );
        $dsig->addReference(
            $dom,
            XMLSecurityDSig::SHA256,
            [XMLSecurityDSig::EXC_C14N],
            ['uri' => '#_body']
        );

        // Private key-ով ստորագրել
        $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $objKey->loadKey(self::KEY_PATH, true);
        $dsig->sign($objKey);

        // Signature-ը տեղադրել o:Security-ի մեջ
        $xpath   = new DOMXPath($dom);
        $xpath->registerNamespace('o', self::WSSE_NS);
        $secNode = $xpath->query('//o:Security')->item(0);

        // Հին ստորագրություն (retry case) — հեռացնել
        $oldSig = $secNode->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'Signature')->item(0);
        if ($oldSig) {
            $secNode->removeChild($oldSig);
        }

        $dsig->appendSignature($secNode);

        // KeyInfo → SecurityTokenReference → BinarySecurityToken-ի reference
        $sigNode = $secNode->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'Signature')->item(0);

        $keyInfo = $dom->createElementNS(XMLSecurityDSig::XMLDSIGNS, 'ds:KeyInfo');

        $str = $dom->createElementNS(self::WSSE_NS, 'o:SecurityTokenReference');

        $ref = $dom->createElementNS(self::WSSE_NS, 'o:Reference');
        $ref->setAttribute('URI',       '#' . $this->bstId);
        $ref->setAttribute('ValueType', self::X509_VALUETYPE);

        $str->appendChild($ref);
        $keyInfo->appendChild($str);
        $sigNode->appendChild($keyInfo);

        return $dom->saveXML();
    }

    // =========================================================================
    // Step 3 — cURL (HTTPS + mTLS)
    // =========================================================================

    private function sendViaCurl(string $action, string $xml): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/soap+xml; charset=utf-8; action="' . self::ACTION_NS . $action . '"',
            ],
            // mTLS
            CURLOPT_SSLCERT        => self::CERT_PATH,
            CURLOPT_SSLKEY         => self::KEY_PATH,
            // Server CA
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO         => self::CA_PATH,
            // IP-ով կապ — hostname check անջատ
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

        return (string) $response;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * SendRequestResult-ը int-ով վերադարձնել (DEGS request ID)
     */
    private function extractSendRequestResult(string $xml): int
    {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml)) {
            throw new \RuntimeException(
                'DEGS: Invalid XML response: ' . substr($xml, 0, 300)
            );
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

    /**
     * SOAP Fault message-ը կարդալ
     */
    private function extractFault(string $xml): string
    {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml)) {
            return substr($xml, 0, 500);
        }

        $xpath = new DOMXPath($dom);
        $parts = [];

        foreach ($xpath->query('//*[local-name()="Text"]') as $node) {
            $parts[] = trim($node->textContent);
        }
        foreach ($xpath->query('//*[local-name()="Value"]') as $node) {
            $parts[] = trim($node->textContent);
        }
        foreach ($xpath->query('//*[local-name()="Detail"]') as $node) {
            $parts[] = trim($node->textContent);
        }

        return $parts
            ? implode(' | ', array_unique(array_filter($parts)))
            : substr($xml, 0, 500);
    }

    /**
     * UUID v4
     */
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
