<?php
require '/var/www/html/test-diamond-credit/vendor/autoload.php';

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

$certPath = '/etc/ssl/degs/client.crt';
$keyPath  = '/etc/ssl/degs/client.key';
$caPath   = '/etc/ssl/certs/DEGSTESTRootCA.pem';
$endpoint = 'https://100.100.100.60:8888/DEGSHost';
$actionUrl = 'http://tempuri.org/IDegsNSS/IsAlive';

function makeUuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
}

// ─── Certificate ──────────────────────────────────────────────────────────────
$certPem = file_get_contents($certPath);
$certDer = base64_decode(str_replace(
    ['-----BEGIN CERTIFICATE-----','-----END CERTIFICATE-----',"\n","\r",' '],
    '', $certPem
));
$certB64    = base64_encode($certDer);
$thumbprint = base64_encode(hash('sha1', $certDer, true));

$msgId = 'urn:uuid:' . makeUuid();
$bstId = 'bst-' . makeUuid();
$tsId  = 'ts-'  . makeUuid();
$toId  = 'to-'  . makeUuid();
$bodyId = 'body-' . makeUuid();

$now = gmdate('Y-m-d\TH:i:s\Z');
$exp = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

$secNs  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$wsuNs  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
$dsigNs = 'http://www.w3.org/2000/09/xmldsig#';
$soapNs = 'http://www.w3.org/2003/05/soap-envelope';
$addrNs = 'http://www.w3.org/2005/08/addressing';

// ─── Build envelope ───────────────────────────────────────────────────────────
$envelope = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<s:Envelope'
    .     ' xmlns:s="' . $soapNs . '"'
    .     ' xmlns:a="' . $addrNs . '"'
    .     ' xmlns:u="' . $wsuNs . '"'
    .     ' xmlns:o="' . $secNs . '">'
    .   '<s:Header>'
    .     '<a:Action s:mustUnderstand="1">' . $actionUrl . '</a:Action>'
    .     '<a:MessageID>' . $msgId . '</a:MessageID>'
    .     '<a:To s:mustUnderstand="1" u:Id="' . $toId . '">' . $endpoint . '</a:To>'
    .     '<o:Security s:mustUnderstand="1">'
    .       '<u:Timestamp u:Id="' . $tsId . '">'
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
    .   '<s:Body u:Id="' . $bodyId . '">'
    .     '<tns:IsAlive xmlns:tns="http://tempuri.org/"/>'
    .   '</s:Body>'
    . '</s:Envelope>';

// ─── Parse DOM ────────────────────────────────────────────────────────────────
$dom = new DOMDocument();
$dom->loadXML($envelope);

// ԿԱՐԵՎՈՐ: u:Id attribute-ները register անել որպես XML ID
// Հակառակ դեպքում xmlseclibs-ի getElementById-ը չի գտնի նոդերը
$xpath = new DOMXPath($dom);
$xpath->registerNamespace('u', $wsuNs);
$xpath->registerNamespace('s', $soapNs);
$xpath->registerNamespace('a', $addrNs);
$xpath->registerNamespace('o', $secNs);

// Բոլոր u:Id attribute ունեցող նոդերը register անել
foreach ($xpath->query('//*[@u:Id]') as $node) {
    $node->setIdAttributeNS($wsuNs, 'Id', true);
}

// Verify registration
$tsNode   = $dom->getElementById($tsId);
$toNode   = $dom->getElementById($toId);
$bodyNode = $dom->getElementById($bodyId);
$bstNode  = $dom->getElementById($bstId);

echo "ID registration check:\n";
echo "  Timestamp [$tsId]: "   . ($tsNode   ? 'OK' : 'FAIL') . "\n";
echo "  To        [$toId]: "   . ($toNode   ? 'OK' : 'FAIL') . "\n";
echo "  Body      [$bodyId]: " . ($bodyNode ? 'OK' : 'FAIL') . "\n";
echo "  BST       [$bstId]: "  . ($bstNode  ? 'OK' : 'FAIL') . "\n\n";

// ─── Sign ─────────────────────────────────────────────────────────────────────
$secNode = $dom->getElementsByTagNameNS($secNs, 'Security')->item(0);

$dsig = new XMLSecurityDSig('');
$dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

// WSDL policy: SignedParts → To header. Plus Timestamp and Body.
foreach (['#' . $tsId, '#' . $toId, '#' . $bodyId] as $ref) {
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

// ─── Fix KeyInfo ──────────────────────────────────────────────────────────────
$sigNode = $secNode->getElementsByTagNameNS($dsigNs, 'Signature')->item(0);
if (!$sigNode) {
    die("ERROR: Signature node missing after appendSignature!\n");
}
echo "Signature node: OK\n";

// Remove auto-generated KeyInfo
$oldKI = $sigNode->getElementsByTagNameNS($dsigNs, 'KeyInfo')->item(0);
if ($oldKI) $sigNode->removeChild($oldKI);

// Add ThumbprintSHA1 KeyInfo (WSDL: RequireThumbprintReference)
$keyInfo = $dom->createElementNS($dsigNs, 'ds:KeyInfo');
$str     = $dom->createElementNS($secNs, 'o:SecurityTokenReference');
$kid     = $dom->createElementNS($secNs, 'o:KeyIdentifier');
$kid->setAttribute('ValueType',
    'http://docs.oasis-open.org/wss/oasis-wss-soap-message-security-1.1#ThumbprintSHA1');
$kid->setAttribute('EncodingType',
    'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary');
$kid->nodeValue = $thumbprint;
$str->appendChild($kid);
$keyInfo->appendChild($str);
$sigNode->appendChild($keyInfo);

$signedXml = $dom->saveXML();

// ─── Verify references count ──────────────────────────────────────────────────
$checkDom = new DOMDocument();
$checkDom->loadXML($signedXml);
$checkXpath = new DOMXPath($checkDom);
$refs = $checkXpath->query('//*[local-name()="Reference"]');
echo "Signed references count: " . $refs->length . " (expected 3)\n";
foreach ($refs as $r) {
    echo "  - " . $r->getAttribute('URI') . "\n";
}

// Check Signature exists
$sigs = $checkXpath->query('//*[local-name()="Signature"]');
echo "Signature elements: " . $sigs->length . " (expected 1)\n\n";

file_put_contents('/tmp/signed_v2.xml', $signedXml);
echo "Saved: /tmp/signed_v2.xml\n\n";

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
$err      = curl_error($ch);
curl_close($ch);

echo "HTTP: $httpCode\n";
if ($err) echo "cURL Error: $err\n";
echo "Response: $response\n";
