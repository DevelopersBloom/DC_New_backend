<?php
/**
 * degs_analyze_fixed.php
 *
 * Ուղղված կետեր.
 *  1. BinarySecurityToken — Timestamp-ից ԱՌԱՋ (WCF EndorsingSupportingTokens պահանջ)
 *  2. KeyInfo — ThumbprintReference (o:KeyIdentifier) փոխարեն o:Reference
 *  3. BST Reference — SignedInfo-ում պահպանված է (WCF AlwaysToRecipient)
 *  4. To header — endpoint URL (ոչ localhost)
 *  5. SHA256 digest + RSA-SHA256 (ինչպես WSDL-ում Basic256)
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
// WCF-ը ThumbprintReference-ի համար պահանջում է այս ValueType
$THUMB_VT = 'http://docs.oasis-open.org/wss/oasis-wss-soap-message-security-1.1#ThumbprintSHA1';

// ── Certificate ──────────────────────────────────────────────
$rawPem  = file_get_contents($CERT_PATH);
$certB64 = str_replace(["\r", "\n", " "], '', preg_replace('/-----[^-]+-----/', '', $rawPem));

// Thumbprint = SHA1 of DER-encoded cert (binary)
$certDer       = base64_decode($certB64);
$thumbprintB64 = base64_encode(hash('sha1', $certDer, true));

// ── IDs / timestamps ─────────────────────────────────────────
$bstId   = 'bst-' . bin2hex(random_bytes(8));
$msgId   = 'urn:uuid:' . uuid4();
$now     = gmdate('Y-m-d\TH:i:s\Z');
$expires = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

// ── 1. Envelope ──────────────────────────────────────────────
// ԿԱՐԵՎՈՐ կառուցվածք Security node-ում:
//   BST  (IncludeToken=AlwaysToRecipient → BST պետք է լինի)
//   Timestamp
//   Signature  (BST + Timestamp + Body digest-ներով)
//
// BST-ն ԱՌԱՋ, Timestamp-ը ՀԵՏՈ — WCF WS-Policy կարգ

$rawXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope
    xmlns:s="{$SOAP_NS}"
    xmlns:a="{$WSA_NS}"
    xmlns:u="{$WSU_NS}"
    xmlns:o="{$WSSE_NS}"
    xmlns:ds="{$DSIG_NS}">
  <s:Header>
    <a:Action s:mustUnderstand="1">http://tempuri.org/IDegsNSS/IsAlive</a:Action>
    <a:MessageID>{$msgId}</a:MessageID>
    <a:To s:mustUnderstand="1" u:Id="_to">{$ENDPOINT}</a:To>
    <o:Security s:mustUnderstand="1">

      <!-- 1. BST — FIRST in Security (EndorsingSupportingTokens) -->
      <o:BinarySecurityToken
          u:Id="{$bstId}"
          ValueType="{$X509VT}"
          EncodingType="{$B64ET}">{$certB64}</o:BinarySecurityToken>

      <!-- 2. Timestamp — AFTER BST -->
      <u:Timestamp u:Id="_ts">
        <u:Created>{$now}</u:Created>
        <u:Expires>{$expires}</u:Expires>
      </u:Timestamp>

      <!-- 3. Signature placeholder — will be filled below -->
      <ds:Signature>
        <ds:SignedInfo>
          <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>
          <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>
          <ds:Reference URI="#_ts">
            <ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms>
            <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#sha256"/>
            <ds:DigestValue>PLACEHOLDER_TS</ds:DigestValue>
          </ds:Reference>
          <ds:Reference URI="#_body">
            <ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms>
            <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
            <ds:DigestValue>PLACEHOLDER_BODY</ds:DigestValue>
          </ds:Reference>
          <ds:Reference URI="#{$bstId}">
            <ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms>
            <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
            <ds:DigestValue>PLACEHOLDER_BST</ds:DigestValue>
          </ds:Reference>
          <ds:Reference URI="#_to">
            <ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms>
            <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
            <ds:DigestValue>PLACEHOLDER_TO</ds:DigestValue>
          </ds:Reference>
        </ds:SignedInfo>
        <ds:SignatureValue>PLACEHOLDER_SIG</ds:SignatureValue>
        <ds:KeyInfo>
          <o:SecurityTokenReference>
            <!-- WCF RequireThumbprintReference → KeyIdentifier ValueType=ThumbprintSHA1 -->
            <o:KeyIdentifier
                ValueType="{$THUMB_VT}"
                EncodingType="{$B64ET}">{$thumbprintB64}</o:KeyIdentifier>
          </o:SecurityTokenReference>
        </ds:KeyInfo>
      </ds:Signature>

    </o:Security>
  </s:Header>
  <s:Body u:Id="_body">
    <IsAlive xmlns="http://tempuri.org/"/>
  </s:Body>
</s:Envelope>
XML;

// ── 2. Parse ──────────────────────────────────────────────────
$dom = new DOMDocument();
$dom->preserveWhiteSpace = false;
$dom->formatOutput       = false;
$dom->loadXML($rawXml);

$xpath = new DOMXPath($dom);
$xpath->registerNamespace('s',  $SOAP_NS);
$xpath->registerNamespace('u',  $WSU_NS);
$xpath->registerNamespace('o',  $WSSE_NS);
$xpath->registerNamespace('a',  $WSA_NS);
$xpath->registerNamespace('ds', $DSIG_NS);

// wsu:Id-երը XML ID-ի վերածել (getElementById-ի համար)
foreach ($xpath->query('//*[@u:Id]') as $node) {
    $node->setIdAttributeNS($WSU_NS, 'Id', true);
}

// ── 3. Node-ների ստացում ──────────────────────────────────────
$tsNode   = $xpath->query('//u:Timestamp[@u:Id="_ts"]')->item(0);
$bodyNode = $xpath->query('//s:Body[@u:Id="_body"]')->item(0);
$bstNode  = $xpath->query('//o:BinarySecurityToken[@u:Id="' . $bstId . '"]')->item(0);
$toNode   = $xpath->query('//a:To[@u:Id="_to"]')->item(0);

if (!$tsNode || !$bodyNode || !$bstNode || !$toNode) {
    die("❌ Required node not found\n");
}

// ── 4. Digests ────────────────────────────────────────────────
$tsDigest   = base64_encode(hash('sha256', $tsNode->C14N(true, false),   true));
$bodyDigest = base64_encode(hash('sha256', $bodyNode->C14N(true, false), true));
$bstDigest  = base64_encode(hash('sha256', $bstNode->C14N(true, false),  true));
$toDigest   = base64_encode(hash('sha256', $toNode->C14N(true, false),   true));

echo "TS digest  : $tsDigest\n";
echo "Body digest: $bodyDigest\n";
echo "BST digest : $bstDigest\n";
echo "To digest  : $toDigest\n";
echo "Thumbprint : $thumbprintB64\n";

// ── 5. DigestValue placeholder-ների լրացում ───────────────────
$dvNodes = $xpath->query('//ds:DigestValue');
if ($dvNodes->length < 4) {
    die("❌ Expected 4 DigestValue nodes, got: " . $dvNodes->length . "\n");
}
$dvNodes->item(0)->nodeValue = $tsDigest;
$dvNodes->item(1)->nodeValue = $bodyDigest;
$dvNodes->item(2)->nodeValue = $bstDigest;
$dvNodes->item(3)->nodeValue = $toDigest;

// ── 6. SignedInfo C14N ────────────────────────────────────────
$siNode = $xpath->query('//ds:SignedInfo')->item(0);
$siC14n = $siNode->C14N(true, false);

echo "\nSignedInfo C14N (first 100): " . substr($siC14n, 0, 100) . "\n";

// ── 7. Sign ───────────────────────────────────────────────────
$privKey = openssl_pkey_get_private('file://' . $KEY_PATH);
if (!$privKey) {
    die("❌ Private key load failed: " . openssl_error_string() . "\n");
}
if (!openssl_sign($siC14n, $rawSig, $privKey, OPENSSL_ALGO_SHA256)) {
    die("❌ Sign failed: " . openssl_error_string() . "\n");
}
$sigValue = base64_encode($rawSig);

// ── 8. Local verify ───────────────────────────────────────────
$pubKey  = openssl_pkey_get_public(file_get_contents($CERT_PATH));
$verify1 = openssl_verify($siC14n, $rawSig, $pubKey, OPENSSL_ALGO_SHA256);
echo "RSA verify: " . ($verify1 === 1 ? '✅ OK' : '❌ FAIL') . "\n";

// ── 9. SignatureValue placeholder-ի լրացում ───────────────────
$xpath->query('//ds:SignatureValue')->item(0)->nodeValue = $sigValue;

$signedXml = $dom->saveXML();
file_put_contents('/tmp/degs_v8.xml', $signedXml);
echo "Saved: /tmp/degs_v8.xml\n\n";

// ── 10. Send ──────────────────────────────────────────────────
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
    CURLOPT_VERBOSE        => false,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo "❌ cURL error: $curlErr\n";
} else {
    echo "HTTP: $httpCode\n$response\n";
}

// ── Helper ────────────────────────────────────────────────────
function uuid4(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
}
