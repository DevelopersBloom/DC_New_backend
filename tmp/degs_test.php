<?php
require '/var/www/html/test-diamond-credit/vendor/autoload.php';

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

$certPath = '/etc/ssl/degs/client.crt';
$keyPath  = '/etc/ssl/degs/client.key';
$caPath   = '/etc/ssl/certs/DEGSTESTRootCA.pem';

$endpoint  = 'https://100.100.100.60:8888/DEGSHost';
$actionUrl = 'http://tempuri.org/IDegsNSS/IsAlive';

$secNs  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$dsigNs = 'http://www.w3.org/2000/09/xmldsig#';
$uNs    = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';

function uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000,
        mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
    );
}

/* ───────────────── CERT ───────────────── */
$certPem = file_get_contents($certPath);

$certClean = str_replace(
    ["-----BEGIN CERTIFICATE-----","-----END CERTIFICATE-----","\n","\r"," "],
    '',
    $certPem
);

$certB64 = base64_encode(base64_decode($certClean));

/* ───────────────── IDS ───────────────── */
$msgId = 'urn:uuid:' . uuid();
$bstId = 'bst-' . uuid();

$now = gmdate('Y-m-d\TH:i:s\Z');
$exp = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

/* ───────────────── SOAP ───────────────── */
$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"
            xmlns:a="http://www.w3.org/2005/08/addressing"
            xmlns:wsu="$uNs"
            xmlns:o="$secNs">

  <s:Header>

    <a:Action s:mustUnderstand="1">$actionUrl</a:Action>
    <a:MessageID>$msgId</a:MessageID>
    <a:To s:mustUnderstand="1">{$endpoint}</a:To>

    <o:Security s:mustUnderstand="1">

      <wsu:Timestamp wsu:Id="TS-1">
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

  <s:Body wsu:Id="BODY-1">
    <tns:IsAlive xmlns:tns="http://tempuri.org/"/>
  </s:Body>

</s:Envelope>
XML;

/* ───────────────── DOM ───────────────── */
$dom = new DOMDocument();
$dom->preserveWhiteSpace = false;
$dom->loadXML($xml);

$xpath = new DOMXPath($dom);
$xpath->registerNamespace('wsu', $uNs);

/* Register ONLY wsu:Id */
foreach ($xpath->query('//*[@wsu:Id]') as $node) {
    $node->setIdAttributeNS($uNs, 'Id', true);
}

/* ───────────────── SIGN ───────────────── */
$securityNode = $dom->getElementsByTagNameNS($secNs, 'Security')->item(0);

$objDSig = new XMLSecurityDSig();
$objDSig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

/*
  SIGN ONLY:
  - Timestamp
  - Body
  - To (optional, but DEGS sometimes requires)
*/
foreach (['TS-1', 'BODY-1'] as $id) {
    $node = $dom->getElementById($id);

    $objDSig->addReference(
        $node,
        XMLSecurityDSig::SHA256,
        ['http://www.w3.org/2001/10/xml-exc-c14n#'],
        ['uri' => '#' . $id]
    );
}

/* Private key */
$key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
$key->loadKey($keyPath, true);

/* Sign */
$objDSig->sign($key);

/* Insert signature */
$objDSig->appendSignature($securityNode);

/* ───────────────── KEYINFO (BST reference) ───────────────── */
$sigNode = $securityNode->getElementsByTagNameNS($dsigNs, 'Signature')->item(0);

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

/* ───────────────── OUTPUT ───────────────── */
$signedXml = $dom->saveXML();
file_put_contents('/tmp/signed_envelope.xml', $signedXml);

echo "Signed XML saved\n";

/* ───────────────── SEND ───────────────── */
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $signedXml,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/soap+xml; charset=utf-8; action="' . $actionUrl . '"'
    ],
    CURLOPT_SSLCERT => $certPath,
    CURLOPT_SSLKEY => $keyPath,
    CURLOPT_CAINFO => $caPath,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 0,
]);

$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP: $code\n";
if ($error) echo "cURL error: $error\n";
echo $response;
