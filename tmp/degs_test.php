<?php
require '/var/www/html/test-diamond-credit/vendor/autoload.php';

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

// ─── Config ───────────────────────────────────────────────────────────────────
$certPath = '/etc/ssl/degs/client.crt';
$keyPath  = '/etc/ssl/degs/client.key';
$caPath   = '/etc/ssl/certs/DEGSTESTRootCA.pem';
$endpoint = 'https://100.100.100.60:8888/DEGSHost';
$action   = 'IsAlive';
$actionUrl = 'http://tempuri.org/IDegsNSS/' . $action;

// ─── UUID helper ──────────────────────────────────────────────────────────────
function uuid(): string {
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
$certB64 = base64_encode($certDer);

$msgId = 'urn:uuid:' . uuid();
$bstId = 'bst-' . uuid();
$now   = gmdate('Y-m-d\TH:i:s\Z');
$exp   = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

// ─── Build envelope ───────────────────────────────────────────────────────────
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
    .         ' xmlns:o="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
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

// ─── Sign ─────────────────────────────────────────────────────────────────────
$secNs  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$dsigNs = 'http://www.w3.org/2000/09/xmldsig#';

$dom = new DOMDocument();
$dom->loadXML($envelope);

$secNode = $dom->getElementsByTagNameNS($secNs, 'Security')->item(0);

$dsig = new XMLSecurityDSig('');
$dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

foreach (['#_ts', '#_to', '#_body'] as $ref) {
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
if (!$sigNode) {
    die("ERROR: Signature node not found after appendSignature\n");
}

// Replace KeyInfo with SecurityTokenReference
$oldKeyInfo = $sigNode->getElementsByTagNameNS($dsigNs, 'KeyInfo')->item(0);
if ($oldKeyInfo) {
    $sigNode->removeChild($oldKeyInfo);
}

$keyInfo = $dom->createElementNS($dsigNs, 'ds:KeyInfo');
$str     = $dom->createElementNS($secNs, 'o:SecurityTokenReference');
$bstRef  = $dom->createElementNS($secNs, 'o:Reference');
$bstRef->setAttribute('URI', '#' . $bstId);
$bstRef->setAttribute('ValueType',
    'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3');
$str->appendChild($bstRef);
$keyInfo->appendChild($str);
$sigNode->appendChild($keyInfo);

$signedXml = $dom->saveXML();

// ─── Send ─────────────────────────────────────────────────────────────────────
echo "Sending signed IsAlive...\n";

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
