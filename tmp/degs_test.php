<?php

/**
 * Tinker-ում գործարկել՝
 *   $debug = require base_path('tmp/debug_soap.php');
 *
 * Կտպի ստորագրված SOAP envelope-ը + certificate տեղեկությունները
 * առանց ուղարկելու, որ կարողանաս ստուգել ձեռքով։
 */

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

$CERT_PATH = '/etc/ssl/degs/client.crt';
$KEY_PATH  = '/etc/ssl/degs/client.key';
$CA_PATH   = '/etc/ssl/certs/DEGSTESTRootCA.pem';
$ENDPOINT  = 'https://100.100.100.60:8888/DEGSHost';

echo "\n=== 1. CERTIFICATE CHECK ===\n";

// Cert կա՞
echo 'client.crt exists : ' . (file_exists($CERT_PATH) ? 'YES' : 'NO ❌') . "\n";
echo 'client.key exists : ' . (file_exists($KEY_PATH)  ? 'YES' : 'NO ❌') . "\n";
echo 'CA.pem    exists  : ' . (file_exists($CA_PATH)   ? 'YES' : 'NO ❌') . "\n";

if (!file_exists($CERT_PATH)) {
    die("❌ Certificate not found — stop.\n");
}

// Cert parse
$certPem  = file_get_contents($CERT_PATH);
$certData = openssl_x509_parse($certPem);
if (!$certData) {
    die("❌ Cannot parse certificate.\n");
}

$now       = time();
$validFrom = $certData['validFrom_time_t'] ?? 0;
$validTo   = $certData['validTo_time_t']   ?? 0;

echo 'Subject           : ' . ($certData['subject']['CN']   ?? '?') . "\n";
echo 'Issuer            : ' . ($certData['issuer']['CN']    ?? '?') . "\n";
echo 'Valid from        : ' . date('Y-m-d H:i:s', $validFrom) . "\n";
echo 'Valid to          : ' . date('Y-m-d H:i:s', $validTo)   . "\n";
echo 'Cert valid NOW    : ' . ($now >= $validFrom && $now <= $validTo ? 'YES ✓' : 'NO ❌ EXPIRED or NOT YET VALID') . "\n";

// Key matches cert?
$keyRes  = openssl_pkey_get_private('file://' . $KEY_PATH);
$keyData = $keyRes ? openssl_pkey_details($keyRes) : null;
echo 'Private key readable: ' . ($keyData ? 'YES ✓' : 'NO ❌') . "\n";

if ($keyData && $certData) {
    // Public key from cert vs public key from private key
    $certPubKey  = openssl_pkey_get_public($certPem);
    $certPubData = openssl_pkey_details($certPubKey);
    $match = ($keyData['key'] === ($certPubData['key'] ?? ''));
    echo 'Key matches cert  : ' . ($match ? 'YES ✓' : 'NO ❌ MISMATCH') . "\n";
}

echo "\n=== 2. BUILD + SIGN ENVELOPE ===\n";

$bstId   = 'bst-test-001';
$msgId   = 'urn:uuid:test-' . uniqid();
$created = gmdate('Y-m-d\TH:i:s\Z');
$expires = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

// Cert base64 (DER)
$certDer = base64_decode(
    str_replace(['-----BEGIN CERTIFICATE-----','-----END CERTIFICATE-----',"\n","\r",' '], '', $certPem)
);
$certB64 = base64_encode($certDer);

$SOAP_NS = 'http://www.w3.org/2003/05/soap-envelope';
$WSA_NS  = 'http://www.w3.org/2005/08/addressing';
$WSU_NS  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
$WSSE_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$X509VT  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
$B64ET   = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

$rawEnvelope = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<s:Envelope'
    .   ' xmlns:s="'   . $SOAP_NS . '"'
    .   ' xmlns:a="'   . $WSA_NS  . '"'
    .   ' xmlns:u="'   . $WSU_NS  . '"'
    .   ' xmlns:o="'   . $WSSE_NS . '">'
    .   '<s:Header>'
    .     '<a:Action s:mustUnderstand="1">http://tempuri.org/IDegsNSS/SendRequest</a:Action>'
    .     '<a:MessageID>' . $msgId . '</a:MessageID>'
    .     '<a:To s:mustUnderstand="1">' . $ENDPOINT . '</a:To>'
    .     '<o:Security s:mustUnderstand="1">'
    .       '<u:Timestamp u:Id="_ts">'
    .         '<u:Created>' . $created . '</u:Created>'
    .         '<u:Expires>' . $expires . '</u:Expires>'
    .       '</u:Timestamp>'
    .       '<o:BinarySecurityToken'
    .         ' u:Id="'         . $bstId . '"'
    .         ' ValueType="'    . $X509VT . '"'
    .         ' EncodingType="' . $B64ET  . '">'
    .         $certB64
    .       '</o:BinarySecurityToken>'
    .     '</o:Security>'
    .   '</s:Header>'
    .   '<s:Body u:Id="_body"><Ping xmlns="http://tempuri.org/"/></s:Body>'
    . '</s:Envelope>';

$dom = new DOMDocument();
$dom->preserveWhiteSpace = false;
$dom->loadXML($rawEnvelope);

$dsig = new XMLSecurityDSig('');
$dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

$dsig->addReference($dom, XMLSecurityDSig::SHA256, [XMLSecurityDSig::EXC_C14N], ['uri' => '#_ts']);
$dsig->addReference($dom, XMLSecurityDSig::SHA256, [XMLSecurityDSig::EXC_C14N], ['uri' => '#_body']);

$objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
$objKey->loadKey($KEY_PATH, true);
$dsig->sign($objKey);

$xpath   = new DOMXPath($dom);
$xpath->registerNamespace('o', $WSSE_NS);
$secNode = $xpath->query('//o:Security')->item(0);
$dsig->appendSignature($secNode);

$sigNode = $secNode->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'Signature')->item(0);
$keyInfo = $dom->createElementNS(XMLSecurityDSig::XMLDSIGNS, 'ds:KeyInfo');
$str     = $dom->createElementNS($WSSE_NS, 'o:SecurityTokenReference');
$ref     = $dom->createElementNS($WSSE_NS, 'o:Reference');
$ref->setAttribute('URI',       '#' . $bstId);
$ref->setAttribute('ValueType', $X509VT);
$str->appendChild($ref);
$keyInfo->appendChild($str);
$sigNode->appendChild($keyInfo);

$signed = $dom->saveXML();
echo "Signed envelope built: " . (strlen($signed) > 500 ? 'YES ✓ (' . strlen($signed) . ' bytes)' : 'NO ❌') . "\n";

echo "\n=== 3. SEND TEST (IsAlive) ===\n";

$testEnvelope = str_replace('<Ping xmlns="http://tempuri.org/"/>', '<IsAlive xmlns="http://tempuri.org/"/>', $signed);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $ENDPOINT,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $testEnvelope,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/soap+xml; charset=utf-8; action="http://tempuri.org/IDegsNSS/IsAlive"',
    ],
    CURLOPT_SSLCERT        => $CERT_PATH,
    CURLOPT_SSLKEY         => $KEY_PATH,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_CAINFO         => $CA_PATH,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_VERBOSE        => true,
]);

$verboseLog = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $verboseLog);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);

rewind($verboseLog);
$verboseOutput = stream_get_contents($verboseLog);
curl_close($ch);

echo "HTTP code  : $httpCode\n";
echo "cURL error : " . ($curlErr ?: 'none') . "\n";
echo "\n--- TLS handshake (excerpt) ---\n";
// TLS info-ն ցուցադրել
$tlsLines = array_filter(explode("\n", $verboseOutput), fn($l) =>
    str_contains($l, 'SSL') || str_contains($l, 'TLS') ||
    str_contains($l, 'certificate') || str_contains($l, 'issuer') ||
    str_contains($l, 'subject') || str_contains($l, 'expire')
);
echo implode("\n", array_slice($tlsLines, 0, 20)) . "\n";

echo "\n--- Response (first 2000 chars) ---\n";
echo substr((string)$response, 0, 2000) . "\n";

echo "\n=== DONE ===\n";

return true;
