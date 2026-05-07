<?php

/**
 * degs_v3.php — Manual WS-Security, URI attribute fix + clean KeyInfo
 */

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

// ── Cert base64 ──────────────────────────────────────────────────
$rawPem = file_get_contents($CERT_PATH);
openssl_x509_export(openssl_x509_read($rawPem), $cleanPem);
$certB64 = preg_replace('/-----[^-]+-----|[\r\n\s]/', '', $cleanPem);

$bstId   = 'bst-' . bin2hex(random_bytes(8));
$msgId   = 'urn:uuid:' . generateUuid();
$now     = gmdate('Y-m-d\TH:i:s\Z');
$expires = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

// ── 1. Build envelope ────────────────────────────────────────────
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

// ── 2. setIdAttributeNS — ԿՐԻՏԻԿԱԿԱՆ ──────────────────────────
// DOM getElementById-ն աշխատում է ՄԻԱՅՆ registered ID attribute-ների համար
foreach ($xpath->query('//*[@u:Id]') as $node) {
    $node->setIdAttributeNS($WSU_NS, 'Id', true);
}

// ── 3. C14N digests — element-ը ՈՒՂՂԱԿԻ xpath-ով ──────────────
// ՈՉ getElementById — query-ն ավելի надежный է
$tsNode   = $xpath->query('//u:Timestamp[@u:Id="_ts"]')->item(0);
$bodyNode = $xpath->query('//s:Body[@u:Id="_body"]')->item(0);

if (!$tsNode)   { die("❌ Timestamp node not found\n"); }
if (!$bodyNode) { die("❌ Body node not found\n"); }

// Exc-C14N յուրաքանչյուր node-ի համար
$tsC14n   = $tsNode->C14N(true, false);
$bodyC14n = $bodyNode->C14N(true, false);

$tsDigest   = base64_encode(hash('sha256', $tsC14n,   true));
$bodyDigest = base64_encode(hash('sha256', $bodyC14n, true));

echo "TS digest   : {$tsDigest}\n";
echo "Body digest : {$bodyDigest}\n";

// Verify digests are different (if same — C14N problem)
if ($tsDigest === $bodyDigest) {
    die("❌ CRITICAL: Both digests identical — C14N is not working per-node!\n");
}
echo "✓ Digests are different\n\n";

// ── 4. SignedInfo ────────────────────────────────────────────────
// URI attribute-ները ՊԱՐՏԱԴԻՐ են
$signedInfoXml = '<SignedInfo xmlns="' . $DSIG_NS . '">'
    . '<CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>'
    . '<SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>'
    . '<Reference URI="#_ts">'
    .   '<Transforms><Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></Transforms>'
    .   '<DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    .   '<DigestValue>' . $tsDigest . '</DigestValue>'
    . '</Reference>'
    . '<Reference URI="#_body">'
    .   '<Transforms><Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></Transforms>'
    .   '<DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    .   '<DigestValue>' . $bodyDigest . '</DigestValue>'
    . '</Reference>'
    . '</SignedInfo>';

// ── 5. Sign ──────────────────────────────────────────────────────
$siDom = new DOMDocument();
$siDom->preserveWhiteSpace = false;
$siDom->loadXML($signedInfoXml);
$siC14n = $siDom->documentElement->C14N(true, false);

$privKey = openssl_pkey_get_private('file://' . $KEY_PATH);
openssl_sign($siC14n, $rawSig, $privKey, OPENSSL_ALGO_SHA256);
$sigValue = base64_encode($rawSig);

// ── 6. Build Signature node via DOM (NO raw string import) ───────
$secNode = $xpath->query('//o:Security')->item(0);

// <ds:Signature> — parent namespace-ից inherit անելու համար
// ՈՉ default namespace, prefix-ով, parent-ի xmlns:o-ն արդեն կա
$sigEl = $dom->createElementNS($DSIG_NS, 'ds:Signature');
$secNode->appendChild($sigEl);

// <ds:SignedInfo> — import existing parsed DOM
$siDom2 = new DOMDocument();
$siDom2->loadXML($signedInfoXml);
$importedSI = $dom->importNode($siDom2->documentElement, true);
$sigEl->appendChild($importedSI);

// <ds:SignatureValue>
$sigValEl = $dom->createElementNS($DSIG_NS, 'ds:SignatureValue', $sigValue);
$sigEl->appendChild($sigValEl);

// <ds:KeyInfo> → <o:SecurityTokenReference> → <o:Reference>
// o: prefix-ն արդեն declared է Envelope-ում — ՈՉ redeclaration
$keyInfoEl = $dom->createElementNS($DSIG_NS, 'ds:KeyInfo');
$strEl     = $dom->createElementNS($WSSE_NS, 'o:SecurityTokenReference');
$refEl     = $dom->createElementNS($WSSE_NS, 'o:Reference');
$refEl->setAttribute('URI',       '#' . $bstId);
$refEl->setAttribute('ValueType', $X509VT);

$strEl->appendChild($refEl);
$keyInfoEl->appendChild($strEl);
$sigEl->appendChild($keyInfoEl);

$signedXml = $dom->saveXML();

// ── 7. Self-verify ───────────────────────────────────────────────
echo "=== Self-verify ===\n";
$vDom = new DOMDocument();
$vDom->loadXML($signedXml);
$vXpath = new DOMXPath($vDom);
$vXpath->registerNamespace('u', $WSU_NS);
foreach ($vXpath->query('//*[@u:Id]') as $node) {
    $node->setIdAttributeNS($WSU_NS, 'Id', true);
}

// Re-compute digests from signed XML
$vXpath2 = new DOMXPath($vDom);
$vXpath2->registerNamespace('s', $SOAP_NS);
$vXpath2->registerNamespace('u', $WSU_NS);
$vXpath2->registerNamespace('ds', $DSIG_NS);

$tsV   = $vXpath2->query('//u:Timestamp[@u:Id="_ts"]')->item(0);
$bodyV = $vXpath2->query('//s:Body[@u:Id="_body"]')->item(0);

$tsDigestV   = base64_encode(hash('sha256', $tsV->C14N(true, false),   true));
$bodyDigestV = base64_encode(hash('sha256', $bodyV->C14N(true, false), true));

$refNodes = $vXpath2->query('//ds:SignedInfo/ds:Reference');
$ref0URI  = $refNodes->item(0)->getAttribute('URI');
$ref0Dig  = $refNodes->item(0)->getElementsByTagNameNS($DSIG_NS, 'DigestValue')->item(0)->textContent;
$ref1URI  = $refNodes->item(1)->getAttribute('URI');
$ref1Dig  = $refNodes->item(1)->getElementsByTagNameNS($DSIG_NS, 'DigestValue')->item(0)->textContent;

echo "Ref[0] URI={$ref0URI}  stored={$ref0Dig}\n";
echo "Ref[0] computed={$tsDigestV} " . ($ref0Dig === $tsDigestV ? '✓' : '❌ MISMATCH') . "\n";
echo "Ref[1] URI={$ref1URI}  stored={$ref1Dig}\n";
echo "Ref[1] computed={$bodyDigestV} " . ($ref1Dig === $bodyDigestV ? '✓' : '❌ MISMATCH') . "\n\n";

// RSA verify
$siNode   = $vXpath2->query('//ds:SignedInfo')->item(0);
$siC14nV  = $siNode->C14N(true, false);
$sigValV  = $vXpath2->query('//ds:SignatureValue')->item(0)->textContent;
$pubKey   = openssl_pkey_get_public(file_get_contents($CERT_PATH));
$verifyR  = openssl_verify($siC14nV, base64_decode($sigValV), $pubKey, OPENSSL_ALGO_SHA256);
echo "RSA verify: " . ($verifyR === 1 ? '✅ VALID' : '❌ INVALID (' . $verifyR . ')') . "\n\n";

// Save for inspection
file_put_contents('/tmp/degs_v3.xml', $signedXml);
echo "Saved: /tmp/degs_v3.xml\n\n";

// ── 8. Send ──────────────────────────────────────────────────────
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
$curlErr  = curl_error($ch);
curl_close($ch);

echo "HTTP: {$httpCode}\n";
echo "Error: " . ($curlErr ?: 'none') . "\n";
echo "Response:\n{$response}\n";

// ── Helpers ──────────────────────────────────────────────────────
function generateUuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0,0xffff), random_int(0,0xffff),
        random_int(0,0xffff),
        random_int(0,0x0fff)|0x4000,
        random_int(0,0x3fff)|0x8000,
        random_int(0,0xffff), random_int(0,0xffff), random_int(0,0xffff)
    );
}
