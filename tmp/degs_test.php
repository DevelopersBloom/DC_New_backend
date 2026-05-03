<?php
require '/var/www/html/test-diamond-credit/vendor/autoload.php';

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

$certPath = '/etc/ssl/degs/client.crt';
$keyPath  = '/etc/ssl/degs/client.key';
$caPath   = '/etc/ssl/certs/DEGSTESTRootCA.pem';
$endpoint = 'https://100.100.100.60:8888/DEGSHost';

function makeUuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
}

function sendRaw(string $xml, string $actionUrl, string $certPath, string $keyPath, string $caPath, string $endpoint): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/soap+xml; charset=utf-8',
            'SOAPAction: "' . $actionUrl . '"',
        ],
        CURLOPT_SSLCERT        => $certPath,
        CURLOPT_SSLKEY         => $keyPath,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO         => $caPath,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    return ['code' => $httpCode, 'response' => $response, 'error' => $err];
}

function buildAndSign(
    string $certPath,
    string $keyPath,
    string $endpoint,
    string $actionUrl,
    array  $refsToSign,    // e.g. ['#_body'] or ['#_ts','#_to','#_body']
    bool   $useThumbprint  // true=ThumbprintSHA1, false=BST Reference
): string {
    $certPem = file_get_contents($certPath);
    $certDer = base64_decode(str_replace(
        ['-----BEGIN CERTIFICATE-----','-----END CERTIFICATE-----',"\n","\r",' '],
        '', $certPem
    ));
    $certB64    = base64_encode($certDer);
    $thumbprint = base64_encode(hash('sha1', $certDer, true));
    $msgId = 'urn:uuid:' . makeUuid();
    $bstId = 'bst-' . makeUuid();
    $now   = gmdate('Y-m-d\TH:i:s\Z');
    $exp   = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

    $secNs  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    $dsigNs = 'http://www.w3.org/2000/09/xmldsig#';

    $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
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
        .         ' xmlns:o="' . $secNs . '">'
        .       '<u:Timestamp u:Id="_ts">'
        .         '<u:Created>' . $now . '</u:Created>'
        .         '<u:Expires>' . $exp . '</u:Expires>'
        .       '</u:Timestamp>'
        .       '<o:BinarySecurityToken'
        .           ' u:Id="' . $bstId . '"'
        .           ' ValueType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3"'
        .           ' EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">'
        .         $certB64
        .       '</o:BinarySecurityToken>'
        .     '</o:Security>'
        .   '</s:Header>'
        .   '<s:Body u:Id="_body">'
        .     '<tns:IsAlive xmlns:tns="http://tempuri.org/"/>'
        .   '</s:Body>'
        . '</s:Envelope>';

    $dom = new DOMDocument();
    $dom->loadXML($envelope);
    $secNode = $dom->getElementsByTagNameNS($secNs, 'Security')->item(0);

    $dsig = new XMLSecurityDSig('');
    $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

    foreach ($refsToSign as $ref) {
        $dsig->addReference(
            $dom,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2001/10/xml-exc-c14n#'],
            ['uri' => $ref, 'overwrite' => false]
        );
    }

    $privKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
    $privKey->loadKey($keyPath, true);
    $dsig->sign($privKey);
    $dsig->appendSignature($secNode);

    $sigNode = $secNode->getElementsByTagNameNS($dsigNs, 'Signature')->item(0);

    // Remove existing KeyInfo
    $old = $sigNode->getElementsByTagNameNS($dsigNs, 'KeyInfo')->item(0);
    if ($old) $sigNode->removeChild($old);

    // Build KeyInfo
    $keyInfo = $dom->createElementNS($dsigNs, 'ds:KeyInfo');
    $str     = $dom->createElementNS($secNs, 'o:SecurityTokenReference');

    if ($useThumbprint) {
        // Option A: ThumbprintSHA1 KeyIdentifier
        $kid = $dom->createElementNS($secNs, 'o:KeyIdentifier');
        $kid->setAttribute('ValueType',
            'http://docs.oasis-open.org/wss/oasis-wss-soap-message-security-1.1#ThumbprintSHA1');
        $kid->setAttribute('EncodingType',
            'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary');
        $kid->nodeValue = $thumbprint;
        $str->appendChild($kid);
    } else {
        // Option B: Direct Reference to BinarySecurityToken
        $ref = $dom->createElementNS($secNs, 'o:Reference');
        $ref->setAttribute('URI', '#' . $bstId);
        $ref->setAttribute('ValueType',
            'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3');
        $str->appendChild($ref);
    }

    $keyInfo->appendChild($str);
    $sigNode->appendChild($keyInfo);

    return $dom->saveXML();
}

// ─── Test variations ──────────────────────────────────────────────────────────

$actionUrl = 'http://tempuri.org/IDegsNSS/IsAlive';

$variations = [
    // [label, refs, useThumbprint]
    ['A: body-only + Thumbprint',          ['#_body'],              true],
    ['B: body-only + BST Reference',       ['#_body'],              false],
    ['C: ts+to+body + Thumbprint',         ['#_ts','#_to','#_body'], true],
    ['D: ts+to+body + BST Reference',      ['#_ts','#_to','#_body'], false],
    ['E: ts+body + Thumbprint',            ['#_ts','#_body'],        true],
    ['F: ts+body + BST Reference',         ['#_ts','#_body'],        false],
];

foreach ($variations as [$label, $refs, $useThumb]) {
    echo "\n========================================\n";
    echo "Testing: $label\n";
    echo "========================================\n";

    $signed = buildAndSign($certPath, $keyPath, $endpoint, $actionUrl, $refs, $useThumb);
    $result = sendRaw($signed, $actionUrl, $certPath, $keyPath, $caPath, $endpoint);

    echo "HTTP: {$result['code']}\n";
    if ($result['error']) echo "cURL: {$result['error']}\n";

    // Parse response
    if (strpos($result['response'], 'IsAliveResult') !== false) {
        echo "SUCCESS! IsAlive returned true!\n";
        echo $result['response'] . "\n";
        // Print working signed XML
        file_put_contents('/tmp/working_signed.xml', $signed);
        echo "Working XML saved to /tmp/working_signed.xml\n";
        break;
    } elseif (strpos($result['response'], 'InvalidSecurity') !== false) {
        echo "FAIL: InvalidSecurity\n";
    } elseif (strpos($result['response'], 'Fault') !== false) {
        // Extract fault text
        preg_match('/<s:Text[^>]*>([^<]+)<\/s:Text>/', $result['response'], $m);
        echo "FAULT: " . ($m[1] ?? $result['response']) . "\n";
    } else {
        echo "Response: " . substr($result['response'], 0, 300) . "\n";
    }
}

echo "\nDone.\n";
