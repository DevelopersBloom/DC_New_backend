<?php

$CERT_PATH = '/etc/ssl/degs/client.crt';
$KEY_PATH  = '/etc/ssl/degs/client.key';
$CA_PATH   = '/etc/ssl/certs/DEGSTESTRootCA.pem';
$ENDPOINT  = 'https://100.100.100.60:8888/DEGSHost';

$SOAP_NS = 'http://www.w3.org/2003/05/soap-envelope';
$WSA_NS  = 'http://www.w3.org/2005/08/addressing';
$WSU_NS  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
$WSSE_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$DSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';
$X509VT  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
$B64ET   = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

$rawPem = file_get_contents($CERT_PATH);
openssl_x509_export(openssl_x509_read($rawPem), $cleanPem);
$certB64 = preg_replace('/-----[^-]+-----|[\r\n\s]/', '', $cleanPem);

$bstId   = 'bst-' . bin2hex(random_bytes(8));
$msgId   = 'urn:uuid:' . uuid4();
$now     = gmdate('Y-m-d\TH:i:s\Z');
$expires = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

// ── 1. Envelope ────────────────────────────────────────────────
$rawXml = <<<XML
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
        <u:Created>{$now}</u:Created>
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
$dom->loadXML($rawXml);

$xpath = new DOMXPath($dom);
$xpath->registerNamespace('s', $SOAP_NS);
$xpath->registerNamespace('u', $WSU_NS);
$xpath->registerNamespace('o', $WSSE_NS);

foreach ($xpath->query('//*[@u:Id]') as $node) {
    $node->setIdAttributeNS($WSU_NS, 'Id', true);
}

// ── 2. Digests — TS, Body, BST ────────────────────────────────
$tsNode   = $xpath->query('//u:Timestamp[@u:Id="_ts"]')->item(0);
$bodyNode = $xpath->query('//s:Body[@u:Id="_body"]')->item(0);
$bstNode  = $xpath->query('//o:BinarySecurityToken[@u:Id="' . $bstId . '"]')->item(0);

if (!$tsNode || !$bodyNode || !$bstNode) {
    die("❌ Node not found: ts=" . ($tsNode?'ok':'NULL') .
        " body=" . ($bodyNode?'ok':'NULL') .
        " bst=" . ($bstNode?'ok':'NULL') . "\n");
}

$tsDigest   = base64_encode(hash('sha256', $tsNode->C14N(true, false),   true));
$bodyDigest = base64_encode(hash('sha256', $bodyNode->C14N(true, false), true));
$bstDigest  = base64_encode(hash('sha256', $bstNode->C14N(true, false),  true));

echo "TS digest  : $tsDigest\n";
echo "Body digest: $bodyDigest\n";
echo "BST digest : $bstDigest\n\n";

// ── 3. SignedInfo — 3 Reference (ts + body + bst) ─────────────
$signedInfoXml = '<ds:SignedInfo'
    . ' xmlns:ds="' . $DSIG_NS . '"'
    . ' xmlns:s="'  . $SOAP_NS . '"'
    . ' xmlns:a="'  . $WSA_NS  . '"'
    . ' xmlns:u="'  . $WSU_NS  . '"'
    . ' xmlns:o="'  . $WSSE_NS . '">'
    // Timestamp
    . '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>'
    . '<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>'
    . '<ds:Reference URI="#_ts">'
    .   '<ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms>'
    .   '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    .   '<ds:DigestValue>' . $tsDigest . '</ds:DigestValue>'
    . '</ds:Reference>'
    // Body
    . '<ds:Reference URI="#_body">'
    .   '<ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms>'
    .   '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    .   '<ds:DigestValue>' . $bodyDigest . '</ds:DigestValue>'
    . '</ds:Reference>'
    // BST — ԿՐԻՏԻԿԱԿԱՆ, WCF-ը սպասում է
    . '<ds:Reference URI="#' . $bstId . '">'
    .   '<ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms>'
    .   '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    .   '<ds:DigestValue>' . $bstDigest . '</ds:DigestValue>'
    . '</ds:Reference>'
    . '</ds:SignedInfo>';

$siDom = new DOMDocument();
$siDom->loadXML($signedInfoXml);
$siC14n = $siDom->documentElement->C14N(true, false);

// ── 4. Sign ────────────────────────────────────────────────────
$privKey = openssl_pkey_get_private('file://' . $KEY_PATH);
openssl_sign($siC14n, $rawSig, $privKey, OPENSSL_ALGO_SHA256);
$sigValue = base64_encode($rawSig);

$pubKey  = openssl_pkey_get_public(file_get_contents($CERT_PATH));
$verify1 = openssl_verify($siC14n, $rawSig, $pubKey, OPENSSL_ALGO_SHA256);
echo "Immediate RSA verify: " . ($verify1 === 1 ? '✅ OK' : '❌ FAIL') . "\n\n";

// ── 5. Insert Signature ────────────────────────────────────────
$secNode = $xpath->query('//o:Security')->item(0);

$sigEl = $dom->createElementNS($DSIG_NS, 'ds:Signature');
$secNode->appendChild($sigEl);

$siDom2 = new DOMDocument();
$siDom2->loadXML($signedInfoXml);
$sigEl->appendChild($dom->importNode($siDom2->documentElement, true));

$sigValEl = $dom->createElementNS($DSIG_NS, 'ds:SignatureValue', $sigValue);
$sigEl->appendChild($sigValEl);

// KeyInfo — o: prefix parent-ից inherit-ով (ոչ redeclare)
$keyInfoEl = $dom->createElementNS($DSIG_NS, 'ds:KeyInfo');
$strEl = $dom->createElement('o:SecurityTokenReference');
$refEl = $dom->createElement('o:Reference');
$refEl->setAttribute('URI',       '#' . $bstId);
$refEl->setAttribute('ValueType', $X509VT);
$strEl->appendChild($refEl);
$keyInfoEl->appendChild($strEl);
$sigEl->appendChild($keyInfoEl);

$signedXml = $dom->saveXML();

// ── 6. Post-save verify ────────────────────────────────────────
$vDom = new DOMDocument();
$vDom->preserveWhiteSpace = false;
$vDom->loadXML($signedXml);
$vXpath = new DOMXPath($vDom);
$vXpath->registerNamespace('ds', $DSIG_NS);
$vXpath->registerNamespace('u',  $WSU_NS);
foreach ($vXpath->query('//*[@u:Id]') as $node) {
    $node->setIdAttributeNS($WSU_NS, 'Id', true);
}
$siC14nFinal = $vXpath->query('//ds:SignedInfo')->item(0)->C14N(true, false);
$match = ($siC14n === $siC14nFinal);
echo "C14N match: " . ($match ? '✅' : '❌ DIFFER') . "\n";
$verify2 = openssl_verify($siC14nFinal, base64_decode($sigValue), $pubKey, OPENSSL_ALGO_SHA256);
echo "Post-save verify: " . ($verify2 === 1 ? '✅ VALID' : '❌ INVALID') . "\n\n";

file_put_contents('/tmp/degs_v6.xml', $signedXml);
echo "Saved: /tmp/degs_v6.xml\n\n";

if ($verify2 !== 1) { die("❌ Not sending\n"); }

// ── 7. Send ────────────────────────────────────────────────────
echo "=== Send IsAlive ===\n";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $ENDPOINT,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $signedXml,
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
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP: $httpCode\n$response\n";

function uuid4(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0,0xffff), random_int(0,0xffff), random_int(0,0xffff),
        random_int(0,0x0fff)|0x4000, random_int(0,0x3fff)|0x8000,
        random_int(0,0xffff), random_int(0,0xffff), random_int(0,0xffff));
}
