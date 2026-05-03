<?php
require '/var/www/html/test-diamond-credit/vendor/autoload.php';

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/* ───────── CONFIG ───────── */
$certPath = '/etc/ssl/degs/client.crt';
$keyPath  = '/etc/ssl/degs/client.key';

$endpoint  = 'https://100.100.100.60:8888/DEGSHost';
$actionUrl = 'http://tempuri.org/IDegsNSS/IsAlive';

/* ───────── NAMESPACES ───────── */
$secNs  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$uNs    = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
$dsigNs = 'http://www.w3.org/2000/09/xmldsig#';

/* ───────── UUID ───────── */
function uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000,
        mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
    );
}

/* ───────── CERT CLEAN ───────── */
$certPem = file_get_contents($certPath);

$certB64 = base64_encode(
    base64_decode(
        str_replace(
            ["-----BEGIN CERTIFICATE-----","-----END CERTIFICATE-----","\n","\r"," "],
            '',
            $certPem
        )
    )
);

/* ───────── IDS ───────── */
$msgId = 'urn:uuid:' . uuid();
$bstId = 'bst-' . uuid();
$tsId  = 'ts-' . uuid();
$bodyId = 'body-' . uuid();

$now = gmdate('Y-m-d\TH:i:s\Z');
$exp = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

/* ───────── SOAP ───────── */
$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"
            xmlns:a="http://www.w3.org/2005/08/addressing"
            xmlns:wsu="$uNs"
            xmlns:o="$secNs"
            xmlns:tns="http://tempuri.org/">

  <s:Header>

    <a:Action s:mustUnderstand="1">$actionUrl</a:Action>
    <a:MessageID>$msgId</a:MessageID>
    <a:To s:mustUnderstand="1">$endpoint</a:To>

    <o:Security s:mustUnderstand="1">

      <wsu:Timestamp wsu:Id="$tsId">
        <wsu:Created>$now</wsu:Created>
        <wsu:Expires>$exp</wsu:Expires>
      </wsu:Timestamp>

      <o:BinarySecurityToken
          wsu:Id="$bstId"
          ValueType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3"
          EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">
          $certB64
      </o:BinarySecurityToken>

    </o:Security>

  </s:Header>

  <s:Body wsu:Id="$bodyId">
    <tns:IsAlive/>
  </s:Body>

</s:Envelope>
XML;

/* ───────── DOM ───────── */
$dom = new DOMDocument();
$dom->preserveWhiteSpace = false;
$dom->loadXML($xml);

$xpath = new DOMXPath($dom);
$xpath->registerNamespace('wsu', $uNs);

/* IMPORTANT: ONLY wsu:Id */
foreach ($xpath->query('//*[@wsu:Id]') as $node) {
    $node->setIdAttributeNS($uNs, 'Id', true);
}

/* ───────── SIGN ───────── */
$securityNode = $dom->getElementsByTagNameNS($secNs, 'Security')->item(0);

$dsig = new XMLSecurityDSig();
$dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

/* ✔ SIGN EXACT ORDER (CRITICAL FOR WCF) */
$refs = [$tsId, $bodyId];

foreach ($refs as $id) {
    $node = $dom->getElementById($id);

    if (!$node) {
        die("Missing ID: $id\n");
    }

    $dsig->addReference(
        $node,
        XMLSecurityDSig::SHA256,
        ['http://www.w3.org/2001/10/xml-exc-c14n#'],
        ['uri' => '#' . $id]
    );
}

/* KEY */
$key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
$key->loadKey($keyPath, true);

/* SIGN */
$dsig->sign($key);

/* INSERT signature */
$dsig->appendSignature($securityNode);

/* ───────── KeyInfo (BST reference) ───────── */
$sigNode = $securityNode->getElementsByTagNameNS($dsigNs, 'Signature')->item(0);

$keyInfo = $dom->createElementNS($dsigNs, 'ds:KeyInfo');

$str = $dom->createElementNS($secNs, 'o:SecurityTokenReference');

$ref = $dom->createElementNS($secNs, 'o:Reference');
$ref->setAttribute('URI', '#' . $bstId);
$ref->setAttribute(
    'ValueType',
    'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3'
);

$str->appendChild($ref);
$keyInfo->appendChild($str);

$sigNode->appendChild($keyInfo);

/* ───────── OUTPUT ───────── */
file_put_contents('/tmp/signed_envelope.xml', $dom->saveXML());
echo "Signed XML saved\n";

/* ───────── SEND ───────── */
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $dom->saveXML(),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/soap+xml; charset=utf-8; action="' . $actionUrl . '"'
    ],
    CURLOPT_SSLCERT => $certPath,
    CURLOPT_SSLKEY => $keyPath,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 0,
]);

$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

echo "HTTP: $code\n";
if ($error) echo "ERROR: $error\n";
echo $response;
