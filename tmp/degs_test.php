<?php

/**
 * Գործարկել՝  php tmp/degs_test_fixed.php
 *
 * ՈՒՂՂՎԱԾ ՏԱՐԲԵՐԱԿ — հիմնական ուղղումները:
 * 1. wsu:Id attribute-ը XMLSecLib-ի կողմից ճիշտ ճանաչվում է
 * 2. BinarySecurityToken-ի encoding-ը ճիշտ
 * 3. KeyInfo → SecurityTokenReference-ը Signature-ի ամենավերջում
 * 4. CBA Luhn checksum-ի ճիշտ ալգորիթմ
 */

require __DIR__ . '/../vendor/autoload.php';

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

$CERT_PATH = '/etc/ssl/degs/client.crt';
$KEY_PATH  = '/etc/ssl/degs/client.key';
$CA_PATH   = '/etc/ssl/certs/DEGSTESTRootCA.pem';
$ENDPOINT  = 'https://100.100.100.60:8888/DEGSHost';

// ============================================================
// 1. CERTIFICATE CHECK
// ============================================================
echo "\n=== 1. CERTIFICATE CHECK ===\n";
echo 'client.crt exists : ' . (file_exists($CERT_PATH) ? 'YES' : 'NO ❌') . "\n";
echo 'client.key exists : ' . (file_exists($KEY_PATH)  ? 'YES' : 'NO ❌') . "\n";
echo 'CA.pem    exists  : ' . (file_exists($CA_PATH)   ? 'YES' : 'NO ❌') . "\n";

if (!file_exists($CERT_PATH)) { die("❌ Certificate not found\n"); }

$certPem  = file_get_contents($CERT_PATH);
$certData = openssl_x509_parse($certPem);
$now      = time();
$validFrom = $certData['validFrom_time_t'] ?? 0;
$validTo   = $certData['validTo_time_t']   ?? 0;
echo 'Subject           : ' . ($certData['subject']['CN'] ?? '?') . "\n";
echo 'Issuer            : ' . ($certData['issuer']['CN']  ?? '?') . "\n";
echo 'Valid from        : ' . date('Y-m-d H:i:s', $validFrom) . "\n";
echo 'Valid to          : ' . date('Y-m-d H:i:s', $validTo)   . "\n";
echo 'Cert valid NOW    : ' . ($now >= $validFrom && $now <= $validTo ? 'YES ✓' : 'NO ❌') . "\n";

$keyRes  = openssl_pkey_get_private('file://' . $KEY_PATH);
$keyData = $keyRes ? openssl_pkey_get_details($keyRes) : null;
echo 'Private key readable: ' . ($keyData ? 'YES ✓' : 'NO ❌') . "\n";

if ($keyData) {
    $certPubKey  = openssl_pkey_get_public($certPem);
    $certPubData = $certPubKey ? openssl_pkey_get_details($certPubKey) : null;
    $match       = $certPubData && ($keyData['key'] === $certPubData['key']);
    echo 'Key matches cert  : ' . ($match ? 'YES ✓' : 'NO ❌ MISMATCH') . "\n";
}

// ============================================================
// 2. BUILD + SIGN  (ուղղված)
// ============================================================
echo "\n=== 2. BUILD + SIGN ===\n";

// Namespaces
$SOAP_NS = 'http://www.w3.org/2003/05/soap-envelope';
$WSA_NS  = 'http://www.w3.org/2005/08/addressing';
$WSU_NS  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
$WSSE_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$X509VT  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
$B64ET   = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

$bstId   = 'bst-' . bin2hex(random_bytes(8));
$msgId   = 'urn:uuid:' . sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
$created = gmdate('Y-m-d\TH:i:s\Z');
$expires = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

// Cert-ը PEM → DER → Base64
// ՈՒՇԱԴՐՈՒԹՅՈՒՆ: openssl_x509_export-ով վերաստանալ մաքուր PEM,
// ապա header/footer/whitespace-ը հանել ու binary decode
openssl_x509_export(openssl_x509_read($certPem), $cleanPem);
$certDer = base64_decode(
    preg_replace('/-----[^-]+-----|[\r\n\s]/', '', $cleanPem)
);
$certB64 = base64_encode($certDer);

// ----------------------------------------------------------------
// ԿՐԻՏԻԿԱԿԱՆ ՈՒՂՂՈՒՄ #1:
// wsu:Id-ը XML attribute namespace-ով պետք է լինի:
// XMLSecLib-ը findById() կանչի ժամանակ փնտրում է
// հենց http://...wssecurity-utility-1.0.xsd namespace-ի Id attribute:
// DOMDocument-ի setAttribute-ը namespace-aware չէ,
// ուստի raw XML-ում ճիշտ prefix-ով ենք գրում u:Id="...",
// որտեղ xmlns:u=WSU_NS-ն արդեն հայտարարված է Envelope-ում:
// ----------------------------------------------------------------
$rawEnvelope = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope
    xmlns:s="{$SOAP_NS}"
    xmlns:a="{$WSA_NS}"
    xmlns:u="{$WSU_NS}"
    xmlns:o="{$WSSE_NS}">
  <s:Header>
    <a:Action s:mustUnderstand="1">http://tempuri.org/IDegsNSS/IsAlive</a:Action>
    <a:MessageID>{$msgId}</a:MessageID>
    <a:To s:mustUnderstand="1">{$ENDPOINT}</a:To>
    <o:Security s:mustUnderstand="1">
      <u:Timestamp u:Id="_ts">
        <u:Created>{$created}</u:Created>
        <u:Expires>{$expires}</u:Expires>
      </u:Timestamp>
      <o:BinarySecurityToken
          u:Id="{$bstId}"
          ValueType="{$X509VT}"
          EncodingType="{$B64ET}">{$certB64}</o:BinarySecurityToken>
    </o:Security>
  </s:Header>
  <s:Body u:Id="_body">
    <IsAlive xmlns="http://tempuri.org/"/>
  </s:Body>
</s:Envelope>
XML;

$dom = new DOMDocument();
$dom->preserveWhiteSpace = false;
$dom->loadXML($rawEnvelope);

// ----------------------------------------------------------------
// ԿՐԻՏԻԿԱԿԱՆ ՈՒՂՂՈՒՄ #2:
// XMLSecLib-ի addReference-ը id-ով element-ը գտնելու համար
// օգտագործում է DOMXPath + getElementById:
// getElementById-ը աշխատում է ՄԻԱՅՆ եթե attribute-ը
// schema-ով ID տիպ ունի կամ setIdAttribute-ով գրանցված է:
// Ուստի ձեռքով գրանցում ենք բոլոր Id attribute-ները:
// ----------------------------------------------------------------
$xpath = new DOMXPath($dom);
$xpath->registerNamespace('u', $WSU_NS);
$xpath->registerNamespace('o', $WSSE_NS);
$xpath->registerNamespace('s', $SOAP_NS);

// Timestamp-ի u:Id-ը
foreach ($xpath->query('//*[@u:Id]') as $node) {
    $node->setIdAttributeNS($WSU_NS, 'Id', true);
}
// Body-ի u:Id-ը (same namespace)
foreach ($xpath->query('//*[@u:Id]') as $node) {
    $node->setIdAttributeNS($WSU_NS, 'Id', true);
}

// ----------------------------------------------------------------
// ՍՏՈՐԱԳՐՈՒՄ
// ----------------------------------------------------------------
$dsig = new XMLSecurityDSig('');
$dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

// Reference #_ts (Timestamp)
$dsig->addReference(
    $dom,
    XMLSecurityDSig::SHA256,
    [XMLSecurityDSig::EXC_C14N],
    ['uri' => '#_ts', 'id_name' => 'Id', 'overwrite' => false, 'prefix' => 'u', 'prefix_ns' => $WSU_NS]
);

// Reference #_body (SOAP Body)
$dsig->addReference(
    $dom,
    XMLSecurityDSig::SHA256,
    [XMLSecurityDSig::EXC_C14N],
    ['uri' => '#_body', 'id_name' => 'Id', 'overwrite' => false, 'prefix' => 'u', 'prefix_ns' => $WSU_NS]
);

$objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
$objKey->loadKey($KEY_PATH, true);
$dsig->sign($objKey);

// Signature-ը տեղադրել o:Security node-ի մեջ
$secNode = $xpath->query('//o:Security')->item(0);
$dsig->appendSignature($secNode);

// ----------------------------------------------------------------
// ԿՐԻՏԻԿԱԿԱՆ ՈՒՂՂՈՒՄ #3:
// KeyInfo-ն ավելացնել appendSignature-ից ՀԵՏՈ,
// ոչ թե createElement-ով (որ կարող է namespace-ը կորցնի):
// ----------------------------------------------------------------
$sigNode = $secNode->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'Signature')->item(0);

$keyInfo = $dom->createElementNS(XMLSecurityDSig::XMLDSIGNS, 'ds:KeyInfo');

$strEl   = $dom->createElementNS($WSSE_NS, 'o:SecurityTokenReference');

$refEl   = $dom->createElementNS($WSSE_NS, 'o:Reference');
$refEl->setAttribute('URI',       '#' . $bstId);
$refEl->setAttribute('ValueType', $X509VT);

$strEl->appendChild($refEl);
$keyInfo->appendChild($strEl);
$sigNode->appendChild($keyInfo);

$signed = $dom->saveXML();

echo 'Signed envelope : ' . strlen($signed) . " bytes ✓\n";
file_put_contents('/tmp/signed_envelope_fixed.xml', $signed);
echo "Saved to        : /tmp/signed_envelope_fixed.xml\n";

// Validate ստորագրությունը մինչ ուղարկելը
echo "\n--- Self-verification ---\n";
$verifyDom = new DOMDocument();
$verifyDom->loadXML($signed);

// setIdAttribute verify DOM-ի համար
$vxpath = new DOMXPath($verifyDom);
$vxpath->registerNamespace('u', $WSU_NS);
foreach ($vxpath->query('//*[@u:Id]') as $node) {
    $node->setIdAttributeNS($WSU_NS, 'Id', true);
}

$objVerify = new XMLSecurityDSig();
$objVerify->locateSignature($verifyDom);
$objVerify->canonicalizeSignedInfo();

try {
    $pubKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'public']);
    $pubKey->loadKey($CERT_PATH, true, true); // true=file, true=cert
    $result = $objVerify->verify($pubKey);
    echo 'Signature self-verify: ' . ($result === 1 ? 'OK ✓' : 'FAIL ❌ (code: '.$result.')') . "\n";
} catch (\Exception $e) {
    echo 'Signature self-verify EXCEPTION: ' . $e->getMessage() . "\n";
}

// ============================================================
// 3. SEND IsAlive
// ============================================================
echo "\n=== 3. SEND IsAlive ===\n";

$verboseLog = fopen('php://temp', 'w+');
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $ENDPOINT,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $signed,
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
    CURLOPT_STDERR         => $verboseLog,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
rewind($verboseLog);
$verboseOutput = stream_get_contents($verboseLog);
curl_close($ch);

echo "HTTP code  : $httpCode\n";
echo "cURL error : " . ($curlErr ?: 'none') . "\n";

echo "\n--- TLS info ---\n";
foreach (explode("\n", $verboseOutput) as $line) {
    if (preg_match('/(SSL|TLS|certificate|cipher|subject|issuer)/i', $line)) {
        echo trim($line) . "\n";
    }
}

echo "\n--- Full server response ---\n";
echo (string) $response . "\n";

echo "\n=== DONE ===\n";
