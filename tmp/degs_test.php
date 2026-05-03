<?php
require '/var/www/html/test-diamond-credit/vendor/autoload.php';

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

$certPath = '/etc/ssl/degs/client.crt';
$keyPath  = '/etc/ssl/degs/client.key';
$caPath   = '/etc/ssl/certs/DEGSTESTRootCA.pem';
$endpoint = 'https://100.100.100.60:8888/DEGSHost';
$actionUrl = 'http://tempuri.org/IDegsNSS/IsAlive';

$secNs  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$dsigNs = 'http://www.w3.org/2000/09/xmldsig#';
$uNs    = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';

function makeUuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
}

// Certificate
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

// ─── Build envelope ───────────────────────────────────────────────────────────
// ԿԱՐԵՎՈՐ: u:Id-ի փոխարեն օգտագործել wsu:Id և xml:id և Id — բոլոր տարբերակները
$envelope = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<s:Envelope'
    .     ' xmlns:s="http://www.w3.org/2003/05/soap-envelope"'
    .     ' xmlns:a="http://www.w3.org/2005/08/addressing"'
    .     ' xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">'
    .   '<s:Header>'
    .     '<a:Action s:mustUnderstand="1">' . $actionUrl . '</a:Action>'
    .     '<a:MessageID>' . $msgId . '</a:MessageID>'
    .     '<a:To s:mustUnderstand="1" wsu:Id="id-to">' . $endpoint . '</a:To>'
    .     '<o:Security'
    .         ' s:mustUnderstand="1"'
    .         ' xmlns:o="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
    .       '<wsu:Timestamp wsu:Id="id-ts">'
    .         '<wsu:Created>' . $now . '</wsu:Created>'
    .         '<wsu:Expires>' . $exp . '</wsu:Expires>'
    .       '</wsu:Timestamp>'
    .       '<o:BinarySecurityToken'
    .           ' wsu:Id="' . $bstId . '"'
    .           ' ValueType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3"'
    .           ' EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">'
    .         $certB64
    .       '</o:BinarySecurityToken>'
    .     '</o:Security>'
    .   '</s:Header>'
    .   '<s:Body wsu:Id="id-body">'
    .     '<tns:IsAlive xmlns:tns="http://tempuri.org/"/>'
    .   '</s:Body>'
    . '</s:Envelope>';

// ─── DOM + register IDs ───────────────────────────────────────────────────────
$dom = new DOMDocument();
$dom->loadXML($envelope);

// ԿԱՐԵՎՈՐ: xmlseclibs-ը getElementById-ով է փնտրում references
// Պետք է setIdAttributeNS կանչել բոլոր ID-ների վրա
$xpath = new DOMXPath($dom);
$xpath->registerNamespace('wsu', $uNs);

// Register բոլոր wsu:Id attribute-ները որպես XML ID
foreach ($xpath->query('//*[@wsu:Id]') as $node) {
    $node->setIdAttributeNS($uNs, 'Id', true);
}

// Verify IDs հասանելի են
echo "=== ID Registration Check ===\n";
foreach (['id-ts', 'id-to', 'id-body', $bstId] as $id) {
    $node = $dom->getElementById($id);

    if (!$node) {
        throw new Exception("ID not found: $id");
    }

    $dsig->addReference(
        $node,
        XMLSecurityDSig::SHA256,
        ['http://www.w3.org/2001/10/xml-exc-c14n#'],
        ['uri' => '#' . $id]
    );
}
// ─── Sign ─────────────────────────────────────────────────────────────────────
$secNode = $dom->getElementsByTagNameNS($secNs, 'Security')->item(0);

$dsig = new XMLSecurityDSig('');
$dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
$dsig->addReference(
    $node,
    XMLSecurityDSig::SHA256,
    [
        'http://www.w3.org/2001/10/xml-exc-c14n#'
    ],
    [
        'uri' => '#' . $id,
        'inclusiveNamespacesPrefixList' => 's a o'
    ]
);
// Ստորագրել Timestamp + To + Body
foreach (['#id-ts', '#id-to', '#id-body'] as $ref) {
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
$timestampNode = $dom->getElementsByTagNameNS($uNs, 'Timestamp')->item(0);
$dsig->insertSignature($secNode, $timestampNode->nextSibling);
// ─── KeyInfo — ThumbprintSHA1 ─────────────────────────────────────────────────
$sigNode = $secNode->getElementsByTagNameNS($dsigNs, 'Signature')->item(0);
echo "\nSignature node: " . ($sigNode ? "OK" : "NOT FOUND") . "\n";

$old = $sigNode->getElementsByTagNameNS($dsigNs, 'KeyInfo')->item(0);
if ($old) $sigNode->removeChild($old);

$keyInfo = $dom->createElementNS($dsigNs, 'ds:KeyInfo');
$str     = $dom->createElementNS($secNs, 'o:SecurityTokenReference');

$ref = $dom->createElementNS($secNs, 'o:Reference');
$ref->setAttribute('URI', '#' . $bstId);
$ref->setAttribute(
    'ValueType',
    'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3'
);

$str->appendChild($ref);
$keyInfo->appendChild($str);
$sigNode->appendChild($keyInfo);

$signedXml = $dom->saveXML();

// Save for inspection
file_put_contents('/tmp/signed_envelope.xml', $signedXml);
echo "Signed XML saved to /tmp/signed_envelope.xml\n\n";

// ─── Send ─────────────────────────────────────────────────────────────────────
echo "Sending...\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $endpoint,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $signedXml,
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
$curlErr  = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($curlErr) echo "cURL Error: $curlErr\n";
echo "Response:\n$response\n";
