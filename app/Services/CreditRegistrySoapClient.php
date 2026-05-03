<?php

namespace App\Services;

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use DOMDocument;
use DOMXPath;

class CreditRegistrySoapClient
{
    private const ENDPOINT   = 'https://100.100.100.60:8888/DEGSHost';
    private const ACTION_NS  = 'http://tempuri.org/IDegsNSS/';
    private const CERT_PATH  = '/etc/ssl/degs/client.crt';
    private const KEY_PATH   = '/etc/ssl/degs/client.key';
    private const CA_PATH    = '/etc/ssl/certs/DEGSTESTRootCA.pem';
    private const APP_NAME   = 'ACREDIT';


    public function sendL001(string $xmlContent, bool $dryRun = false): int
    {
        return $this->sendRequest('L001', $xmlContent, false, $dryRun);
    }

    public function sendL002(string $xmlContent, bool $dryRun = false): int
    {
        return $this->sendRequest('L002', $xmlContent, false, $dryRun);
    }

    public function sendL003(string $xmlContent, bool $dryRun = false): int
    {
        return $this->sendRequest('L003', $xmlContent, false, $dryRun);
    }


    public function isResponsePrepared(int $requestId): bool
    {
        $body = '<tns:IsResponsePrepared xmlns:tns="http://tempuri.org/">
                    <tns:requsetId>' . $requestId . '</tns:requsetId>
                 </tns:IsResponsePrepared>';

        $responseXml = $this->callSoap('IsResponsePrepared', $body);
        $dom = new DOMDocument();
        $dom->loadXML($responseXml);
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[local-name()="IsResponsePreparedResult"]');

        return $nodes->length > 0 && strtolower($nodes->item(0)->textContent) === 'true';
    }


    public function getResponse(int $requestId): string
    {
        $body = '<tns:GetResponse xmlns:tns="http://tempuri.org/">
                    <tns:requsetId>' . $requestId . '</tns:requsetId>
                 </tns:GetResponse>';

        return $this->callSoap('GetResponse', $body);
    }


    public function isAlive(): bool
    {
        $body = '<tns:IsAlive xmlns:tns="http://tempuri.org/"/>';
        try {
            $response = $this->callSoap('IsAlive', $body);
            $dom = new DOMDocument();
            $dom->loadXML($response);
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query('//*[local-name()="IsAliveResult"]');
            return $nodes->length > 0 && strtolower($nodes->item(0)->textContent) === 'true';
        } catch (\Throwable) {
            return false;
        }
    }


    private function sendRequest(
        string $docType,
        string $xmlContent,
        bool   $isDelay,
        bool   $dryRun
    ): int {
        $escapedXml = htmlspecialchars($xmlContent, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $body = sprintf(
            '<tns:SendRequest xmlns:tns="http://tempuri.org/">
                <tns:AppName>%s</tns:AppName>
                <tns:DocType>%s</tns:DocType>
                <tns:IsDelay>%s</tns:IsDelay>
                <tns:xml>%s</tns:xml>
             </tns:SendRequest>',
            self::APP_NAME,
            $docType,
            $isDelay ? 'true' : 'false',
            $escapedXml
        );

        if ($dryRun) {
            $envelope = $this->buildEnvelope('SendRequest', $body);
            $signed   = $this->signEnvelope($envelope);
            \Log::debug('DEGS DryRun SOAP Envelope', ['xml' => $signed]);
            return 0;
        }

        $responseXml = $this->callSoap('SendRequest', $body);

        return $this->extractSendRequestResult($responseXml);
    }

    private function callSoap(string $action, string $bodyContent): string
    {
        $envelope = $this->buildEnvelope($action, $bodyContent);
        $signed   = $this->signEnvelope($envelope);

        return $this->sendViaCurl($signed, $action);
    }


    private function buildEnvelope(string $action, string $bodyContent): string
    {
        $msgId    = 'urn:uuid:' . $this->uuid();
        $now      = gmdate('Y-m-d\TH:i:s\Z');
        $expires  = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
        $endpoint = self::ENDPOINT;
        $actionUrl = self::ACTION_NS . $action;

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope
    xmlns:s="http://www.w3.org/2003/05/soap-envelope"
    xmlns:a="http://www.w3.org/2005/08/addressing"
    xmlns:u="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
  <s:Header>
    <a:Action s:mustUnderstand="1">{$actionUrl}</a:Action>
    <a:MessageID>{$msgId}</a:MessageID>
    <a:To s:mustUnderstand="1" u:Id="_to">{$endpoint}</a:To>
    <o:Security
        s:mustUnderstand="1"
        xmlns:o="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
      <u:Timestamp u:Id="_ts">
        <u:Created>{$now}</u:Created>
        <u:Expires>{$expires}</u:Expires>
      </u:Timestamp>
    </o:Security>
  </s:Header>
  <s:Body u:Id="_body">
    {$bodyContent}
  </s:Body>
</s:Envelope>
XML;
    }


    private function signEnvelope(string $envelopeXml): string
    {
        $dom = new DOMDocument();
        $dom->loadXML($envelopeXml);

        $dsig = new XMLSecurityDSig('');
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

        // WSDL policy: SignedParts → Header "To" + Body
        $dsig->addReference(
            $dom,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2001/10/xml-exc-c14n#'],
            ['uri' => '#_body', 'overwrite' => false]
        );

        $dsig->addReference(
            $dom,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2001/10/xml-exc-c14n#'],
            ['uri' => '#_to', 'overwrite' => false]
        );

        $dsig->addReference(
            $dom,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2001/10/xml-exc-c14n#'],
            ['uri' => '#_ts', 'overwrite' => false]
        );

        // Private key load
        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey(self::KEY_PATH, true);

        $dsig->sign($key);

        // Certificate — ThumbprintReference (WSDL policy-ն սա է պահանջում)
        $certPem = file_get_contents(self::CERT_PATH);
        $certDer = base64_decode(
            str_replace(
                ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r", " "],
                '',
                $certPem
            )
        );
        $thumbprint = base64_encode(hash('sha1', $certDer, true));

        $secNs = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
        $secNode = $dom->getElementsByTagNameNS($secNs, 'Security')->item(0);

        // BinarySecurityToken
        $bstId  = 'uuid-' . $this->uuid();
        $bst    = $dom->createElementNS($secNs, 'o:BinarySecurityToken');
        $bst->setAttribute('u:Id', $bstId);
        $bst->setAttribute(
            'ValueType',
            'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3'
        );
        $bst->setAttribute(
            'EncodingType',
            'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary'
        );
        $bst->nodeValue = base64_encode($certDer);
        $secNode->insertBefore($bst, $secNode->firstChild);

        $sigNode = $dom->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->item(0);
        if ($sigNode) {
            $keyInfo = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:KeyInfo');

            $str = $dom->createElementNS($secNs, 'o:SecurityTokenReference');
            $str->setAttributeNS(
                'http://docs.oasis-open.org/wss/oasis-wss-wssecurity-secext-1.1.xsd',
                'o11:TokenType',
                'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3'
            );

            $kid = $dom->createElementNS($secNs, 'o:KeyIdentifier');
            $kid->setAttribute(
                'ValueType',
                'http://docs.oasis-open.org/wss/oasis-wss-soap-message-security-1.1#ThumbprintSHA1'
            );
            $kid->setAttribute(
                'EncodingType',
                'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary'
            );
            $kid->nodeValue = $thumbprint;

            $str->appendChild($kid);
            $keyInfo->appendChild($str);
            $sigNode->appendChild($keyInfo);

            $secNode->appendChild($sigNode);
        }

        return $dom->saveXML();
    }

    // ─── cURL sender (mTLS) ───────────────────────────────────────────────────

    private function sendViaCurl(string $signedXml, string $action): string
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
            // mTLS
            CURLOPT_SSLCERT        => self::CERT_PATH,
            CURLOPT_SSLKEY         => self::KEY_PATH,
            // CA verification
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO         => self::CA_PATH,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException("cURL error: {$curlErr}");
        }

        if ($httpCode >= 400) {
            $fault = $this->extractFault($response);
            throw new \RuntimeException("SOAP HTTP {$httpCode}: {$fault}");
        }

        return $response;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function extractSendRequestResult(string $xml): int
    {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml)) {
            throw new \RuntimeException('Invalid XML response: ' . substr($xml, 0, 300));
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[local-name()="SendRequestResult"]');

        if ($nodes->length === 0) {
            throw new \RuntimeException('SendRequestResult not found in response');
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
        $texts = $xpath->query('//*[local-name()="Text"] | //*[local-name()="Value"]');
        $parts = [];
        foreach ($texts as $t) {
            $parts[] = $t->textContent;
        }
        return implode(' | ', $parts) ?: $xml;
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
