<?php
/**
 * degs_analyze_fixed.php
 *
 * Ուղղված կետեր:
 *  1. Signature placeholder-ը ՀԵՌԱՑՎՈՒՄ է digest հաշվելուց ԱՌԱՋ
 *  2. XML comments-ը template-ում չկան
 *  3. BST-ն Timestamp-ից ԱՌԱՋ է (WCF EndorsingSupportingTokens)
 *  4. KeyInfo — ThumbprintSHA1 KeyIdentifier (RequireThumbprintReference)
 *  5. Բոլոր DigestMethod-ները — xmldsig-more#sha256 (Basic256)
 *  6. Signature-ն build արվում է digest-ներից ՀԵՏՈ և append արվում Security-ի վերջում
 */

$CERT_PATH = '/etc/ssl/degs/client.crt';
$KEY_PATH  = '/etc/ssl/degs/client.key';
$CA_PATH   = '/etc/ssl/certs/DEGSTESTRootCA.pem';
$ENDPOINT  = 'https://100.100.100.60:8888/DEGSHost';

$SOAP_NS  = 'http://www.w3.org/2003/05/soap-envelope';
$WSA_NS   = 'http://www.w3.org/2005/08/addressing';
$WSU_NS   = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
$WSSE_NS  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$DSIG_NS  = 'http://www.w3.org/2000/09/xmldsig#';
$X509VT   = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
$B64ET    = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';
$THUMB_VT = 'http://docs.oasis-open.org/wss/oasis-wss-soap-message-security-1.1#ThumbprintSHA1';

// ── Certificate ──────────────────────────────────────────────────────────────
$rawPem  = file_get_contents($CERT_PATH);
$certB64 = str_replace(["\r", "\n", " "], '', preg_replace('/-----[^-]+-----/', '', $rawPem));

// Thumbprint = SHA1 of DER binary
$certDer       = base64_decode($certB64);
$thumbprintB64 = base64_encode(hash('sha1', $certDer, true));

// ── IDs / Timestamps ─────────────────────────────────────────────────────────
$bstId   = 'bst-' . bin2hex(random_bytes(8));
$msgId   = 'urn:uuid:' . uuid4();
$now     = gmdate('Y-m-d\TH:i:s\Z');
$expires = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

// ── 1. Envelope (NO Signature placeholder, NO XML comments) ──────────────────
//
// Կառուցվածք Security-ում:
//   BST        (AlwaysToRecipient — BST FIRST)
//   Timestamp
//   [Signature-ն կավելացվի ծրագրայինորեն step 9-ում]
//
$rawXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope
    xmlns:s="{$SOAP_NS}"
    xmlns:a="{$WSA_NS}"
    xmlns:u="{$WSU_NS}"
    xmlns:o="{$WSSE_NS}"
    xmlns:ds="{$DSIG_NS}">
  <s:Header>
    <a:Action s:mustUnderstand="1" u:Id="_action">http://tempuri.org/IDegsNSS/IsAlive</a:Action>
    <a:MessageID u:Id="_msgid">{$msgId}</a:MessageID>
    <a:To s:mustUnderstand="1" u:Id="_to">{$ENDPOINT}</a:To>
    <o:Security s:mustUnderstand="1">
      <o:BinarySecurityToken
          u:Id="{$bstId}"
          ValueType="{$X509VT}"
          EncodingType="{$B64ET}">{$certB64}</o:BinarySecurityToken>
      <u:Timestamp u:Id="_ts">
        <u:Created>{$now}</u:Created>
        <u:Expires>{$expires}</u:Expires>
      </u:Timestamp>
    </o:Security>
  </s:Header>
  <s:Body u:Id="_body">
    <IsAlive xmlns="http://tempuri.org/"/>
  </s:Body>
</s:Envelope>
XML;

// ── 2. Parse ─────────────────────────────────────────────────────────────────
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

// wsu:Id → XML ID
foreach ($xpath->query('//*[@u:Id]') as $node) {
    $node->setIdAttributeNS($WSU_NS, 'Id', true);
}

// ── 3. Node-ների ստացում ─────────────────────────────────────────────────────
// Signature placeholder չկա — digest-ները ճիշտ կլինեն
$tsNode   = $xpath->query('//u:Timestamp[@u:Id="_ts"]')->item(0);
$bodyNode = $xpath->query('//s:Body[@u:Id="_body"]')->item(0);
$bstNode  = $xpath->query('//o:BinarySecurityToken[@u:Id="' . $bstId . '"]')->item(0);
$toNode   = $xpath->query('//a:To[@u:Id="_to"]')->item(0);
$actionNode = $xpath->query('//a:Action[@u:Id="_action"]')->item(0);
$msgIdNode  = $xpath->query('//a:MessageID[@u:Id="_msgid"]')->item(0);

if (!$tsNode || !$bodyNode || !$bstNode || !$toNode || !$actionNode || !$msgIdNode) {
    die("❌ Required node not found\n");
}

// ── 4. Digests (Exc-C14N + InclusiveNamespaces, SHA256) ─────────────────────
// IMPORTANT: digest MUST match ds:Transform output (InclusiveNamespaces PrefixList)
$incPrefixes = ['s', 'a', 'u', 'o'];
$tsDigest   = base64_encode(hash('sha256', $tsNode->C14N(true, false, null, $incPrefixes),   true));
$bodyDigest = base64_encode(hash('sha256', $bodyNode->C14N(true, false, null, $incPrefixes), true));
$toDigest   = base64_encode(hash('sha256', $toNode->C14N(true, false, null, $incPrefixes),   true));
$actionDigest = base64_encode(hash('sha256', $actionNode->C14N(true, false, null, $incPrefixes), true));
$msgIdDigest  = base64_encode(hash('sha256', $msgIdNode->C14N(true, false, null, $incPrefixes), true));

echo "TS digest  : $tsDigest\n";
echo "Body digest: $bodyDigest\n";
echo "To digest  : $toDigest\n";
echo "Action dig.: $actionDigest\n";
echo "MsgID dig.: $msgIdDigest\n";
echo "Thumbprint : $thumbprintB64\n";

// ── 5. SignedInfo build ───────────────────────────────────────────────────────
$sigMethodAlg  = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
$c14nAlg       = 'http://www.w3.org/2001/10/xml-exc-c14n#';
$digestAlg     = 'http://www.w3.org/2001/04/xmlenc#sha256';
$exc14nNs      = 'http://www.w3.org/2001/10/xml-exc-c14n#';
$prefixList    = 's a u o';

$signedInfo = $dom->createElementNS($DSIG_NS, 'ds:SignedInfo');

$canonMethod = $dom->createElementNS($DSIG_NS, 'ds:CanonicalizationMethod');
$canonMethod->setAttribute('Algorithm', $c14nAlg);
$inclusive = $dom->createElementNS($exc14nNs, 'ec:InclusiveNamespaces');
$inclusive->setAttribute('PrefixList', $prefixList);
$canonMethod->appendChild($inclusive);
$signedInfo->appendChild($canonMethod);

$sigMethod = $dom->createElementNS($DSIG_NS, 'ds:SignatureMethod');
$sigMethod->setAttribute('Algorithm', $sigMethodAlg);
$signedInfo->appendChild($sigMethod);

// Helper — Reference node ստեղծել
$addRef = function (string $uri, string $digest) use ($dom, $signedInfo, $DSIG_NS, $c14nAlg, $digestAlg) {
    $ref = $dom->createElementNS($DSIG_NS, 'ds:Reference');
    $ref->setAttribute('URI', $uri);

    $transforms = $dom->createElementNS($DSIG_NS, 'ds:Transforms');
    $transform  = $dom->createElementNS($DSIG_NS, 'ds:Transform');
    $transform->setAttribute('Algorithm', $c14nAlg);
    $inclusive = $dom->createElementNS('http://www.w3.org/2001/10/xml-exc-c14n#', 'ec:InclusiveNamespaces');
    $inclusive->setAttribute('PrefixList', 's a u o');
    $transform->appendChild($inclusive);
    $transforms->appendChild($transform);
    $ref->appendChild($transforms);

    $dm = $dom->createElementNS($DSIG_NS, 'ds:DigestMethod');
    $dm->setAttribute('Algorithm', $digestAlg);
    $ref->appendChild($dm);

    $dv = $dom->createElementNS($DSIG_NS, 'ds:DigestValue', $digest);
    $ref->appendChild($dv);

    $signedInfo->appendChild($ref);
};

// References: ts, body, to, action, messageId
$addRef('#_ts',    $tsDigest);
$addRef('#_body',  $bodyDigest);
$addRef('#_to',    $toDigest);
$addRef('#_action', $actionDigest);
$addRef('#_msgid',  $msgIdDigest);

// ── 6. SignedInfo C14N (must match CanonicalizationMethod InclusiveNamespaces) ─
$siDom = new DOMDocument();
$siDom->preserveWhiteSpace = false;
$siDom->appendChild($siDom->importNode($signedInfo, true));
$signedInfoC14n = $siDom->documentElement->C14N(true, false, null, $incPrefixes);

echo "\nSignedInfo C14N (first 120): " . substr($signedInfoC14n, 0, 120) . "\n";

// ── 7. Sign ───────────────────────────────────────────────────────────────────
$privKey = openssl_pkey_get_private('file://' . $KEY_PATH);
if (!$privKey) {
    die("❌ Private key load failed: " . openssl_error_string() . "\n");
}
if (!openssl_sign($signedInfoC14n, $rawSig, $privKey, OPENSSL_ALGO_SHA256)) {
    die("❌ Sign failed: " . openssl_error_string() . "\n");
}
$sigValue = base64_encode($rawSig);

// ── 8. Local verify ───────────────────────────────────────────────────────────
$pubKey  = openssl_pkey_get_public(file_get_contents($CERT_PATH));
$verify  = openssl_verify($signedInfoC14n, $rawSig, $pubKey, OPENSSL_ALGO_SHA256);
echo "RSA verify: " . ($verify === 1 ? '✅ OK' : '❌ FAIL') . "\n\n";

// ── 9. Signature node build ───────────────────────────────────────────────────
$signatureNode = $dom->createElementNS($DSIG_NS, 'ds:Signature');

// 9a. SignedInfo
$signatureNode->appendChild($signedInfo);

// 9b. SignatureValue
$signatureNode->appendChild(
    $dom->createElementNS($DSIG_NS, 'ds:SignatureValue', $sigValue)
);

// 9c. KeyInfo — ThumbprintSHA1 (sp:RequireThumbprintReference)
$keyInfo = $dom->createElementNS($DSIG_NS, 'ds:KeyInfo');
$str     = $dom->createElementNS($WSSE_NS, 'o:SecurityTokenReference');
$ki      = $dom->createElementNS($WSSE_NS, 'o:KeyIdentifier', $thumbprintB64);
$ki->setAttribute('ValueType', $THUMB_VT);
$ki->setAttribute('EncodingType', $B64ET);
$str->appendChild($ki);
$keyInfo->appendChild($str);
$signatureNode->appendChild($keyInfo);

// ── 10. Append Signature to Security ─────────────────────────────────────────
$secNode = $xpath->query('//o:Security')->item(0);
if (!$secNode) {
    die("❌ Security node not found\n");
}
$secNode->appendChild($signatureNode);

// ── 11. Save ──────────────────────────────────────────────────────────────────
$signedXml = $dom->saveXML();
file_put_contents('/tmp/degs_v9.xml', $signedXml);
echo "Saved: /tmp/degs_v9.xml\n\n";

// ── 12. Send ──────────────────────────────────────────────────────────────────
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

// ── Helper ────────────────────────────────────────────────────────────────────
function uuid4(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
}
