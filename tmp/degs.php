<?php

echo '=== DEGS WS-Security Self-Verification ===' . PHP_EOL . PHP_EOL;

$certPath = '/etc/ssl/degs/client.crt';
$keyPath  = '/etc/ssl/degs/client.key';
$caPath   = '/etc/ssl/certs/DEGSTESTRootCA.pem';

// ── 1. Files exist ───────────────────────────────────────────────
echo '1. Files:' . PHP_EOL;
echo '   client.crt : ' . (file_exists($certPath) ? '✅ exists' : '❌ NOT FOUND') . PHP_EOL;
echo '   client.key : ' . (file_exists($keyPath)  ? '✅ exists' : '❌ NOT FOUND') . PHP_EOL;
echo '   RootCA.pem : ' . (file_exists($caPath)   ? '✅ exists' : '❌ NOT FOUND') . PHP_EOL . PHP_EOL;

// ── 2. Cert parse ────────────────────────────────────────────────
echo '2. Certificate parse:' . PHP_EOL;

$certContent = @file_get_contents($certPath);
if (!$certContent) {
    echo '   ❌ Cannot read certificate file' . PHP_EOL;
    exit(1);
}

$certData = openssl_x509_read($certContent);
if (!$certData) {
    echo '   ❌ Cannot parse certificate: ' . openssl_error_string() . PHP_EOL;
    exit(1);
}

$certInfo = openssl_x509_parse($certData);

echo '   Subject : ' . ($certInfo['subject']['CN'] ?? '-') . PHP_EOL;
echo '   Issuer  : ' . ($certInfo['issuer']['CN'] ?? '-') . PHP_EOL;
echo '   Serial  : ' . strtoupper($certInfo['serialNumberHex'] ?? '-') . PHP_EOL;
echo '   Valid   : ' . date('Y-m-d', $certInfo['validFrom_time_t']) . ' → ' . date('Y-m-d', $certInfo['validTo_time_t']) . PHP_EOL;

$now = time();
if ($now < $certInfo['validFrom_time_t'] || $now > $certInfo['validTo_time_t']) {
    echo '   ❌ Certificate EXPIRED or not yet valid!' . PHP_EOL;
} else {
    echo '   ✅ Certificate is currently valid' . PHP_EOL;
}
echo PHP_EOL;

// ── 3. Key matches cert ──────────────────────────────────────────
echo '3. Key ↔ Certificate match:' . PHP_EOL;

$keyData = openssl_pkey_get_private('file://' . $keyPath);
if (!$keyData) {
    echo '   ❌ Cannot load private key: ' . openssl_error_string() . PHP_EOL;
    exit(1);
}

$certPubKey = openssl_pkey_get_public($certData);

$certDetails = openssl_pkey_get_details($certPubKey);
$keyDetails  = openssl_pkey_get_details($keyData);

if (!isset($certDetails['rsa']['n']) || !isset($keyDetails['rsa']['n'])) {
    echo '   ❌ Cannot extract modulus (non-RSA key?)' . PHP_EOL;
} elseif ($certDetails['rsa']['n'] === $keyDetails['rsa']['n']) {
    echo '   ✅ Private key matches certificate' . PHP_EOL;
} else {
    echo '   ❌ Private key does NOT match certificate!' . PHP_EOL;
}
echo PHP_EOL;

// ── 4. Cert signed by RootCA ─────────────────────────────────────
echo '4. Certificate chain (RootCA verify):' . PHP_EOL;

$caContent = @file_get_contents($caPath);
if (!$caContent) {
    echo '   ❌ Cannot read CA file' . PHP_EOL;
} else {
    $caStore = openssl_x509_read($caContent);
    $verify  = openssl_x509_verify($certData, openssl_pkey_get_public($caStore));

    if ($verify === 1) {
        echo '   ✅ client.crt is signed by DEGS TEST Root CA' . PHP_EOL;
    } else {
        echo '   ❌ client.crt is NOT signed by the provided Root CA!' . PHP_EOL;
    }
}
echo PHP_EOL;

// ── 5. Sign + Verify roundtrip ───────────────────────────────────
echo '5. RSA Sign/Verify roundtrip:' . PHP_EOL;

$testData = 'DEGS-TEST-' . time();

openssl_sign($testData, $sig, $keyData, OPENSSL_ALGO_SHA256);

$pubKey = openssl_pkey_get_public($certData);
$result = openssl_verify($testData, $sig, $pubKey, OPENSSL_ALGO_SHA256);

echo '   ' . ($result === 1 ? '✅ Sign/Verify OK' : '❌ Sign/Verify FAILED') . PHP_EOL . PHP_EOL;

// ── 6. WS-Security XML Signature test ────────────────────────────
echo '6. WS-Security XML Signature test:' . PHP_EOL;

$wsuNs  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
$soapNs = 'http://www.w3.org/2003/05/soap-envelope';
$dsigNs = 'http://www.w3.org/2000/09/xmldsig#';
$wsseNs = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

$now     = gmdate('Y-m-d\TH:i:s\Z');
$expires = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

$testXml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<s:Envelope xmlns:s="' . $soapNs . '" xmlns:u="' . $wsuNs . '">'
    .   '<s:Header>'
    .     '<o:Security xmlns:o="' . $wsseNs . '">'
    .       '<u:Timestamp u:Id="_ts">'
    .         '<u:Created>' . $now . '</u:Created>'
    .         '<u:Expires>' . $expires . '</u:Expires>'
    .       '</u:Timestamp>'
    .     '</o:Security>'
    .   '</s:Header>'
    .   '<s:Body u:Id="_body"><ping/></s:Body>'
    . '</s:Envelope>';

$dom = new DOMDocument();
$dom->preserveWhiteSpace = false;
$dom->loadXML($testXml);

$xpath = new DOMXPath($dom);
$xpath->registerNamespace('s', $soapNs);
$xpath->registerNamespace('u', $wsuNs);

$tsNode   = $xpath->query('//u:Timestamp')->item(0);
$bodyNode = $xpath->query('//s:Body')->item(0);

$tsC14n   = $tsNode->C14N(true, false);
$bodyC14n = $bodyNode->C14N(true, false);

$tsDigest   = base64_encode(hash('sha256', $tsC14n, true));
$bodyDigest = base64_encode(hash('sha256', $bodyC14n, true));

echo '   TS digest   : ' . $tsDigest . PHP_EOL;
echo '   Body digest : ' . $bodyDigest . PHP_EOL;

$signedInfoXml = '<SignedInfo xmlns="' . $dsigNs . '">'
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

$siDom = new DOMDocument();
$siDom->loadXML($signedInfoXml);

$siC14n = $siDom->documentElement->C14N(true, false);

openssl_sign($siC14n, $rawSig, $keyData, OPENSSL_ALGO_SHA256);

$verifyResult = openssl_verify(
    $siC14n,
    $rawSig,
    openssl_pkey_get_public($certData),
    OPENSSL_ALGO_SHA256
);

echo '   Signature self-verify: ' . ($verifyResult === 1 ? '✅ VALID' : '❌ INVALID') . PHP_EOL . PHP_EOL;

// ── 7. TLS connectivity ──────────────────────────────────────────
echo '7. TLS connectivity to DEGS:' . PHP_EOL;

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://100.100.100.60:8888/DEGSHost',
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => '<ping/>',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_HTTPHEADER     => ['Content-Type: text/plain'],
    CURLOPT_SSLCERT        => $certPath,
    CURLOPT_SSLKEY         => $keyPath,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_CAINFO         => $caPath,
    CURLOPT_SSL_VERIFYHOST => 0,
]);

curl_exec($ch);

$tlsErr   = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($tlsErr) {
    echo '   ❌ TLS Error: ' . $tlsErr . PHP_EOL;
} else {
    echo '   ✅ TLS connected (HTTP ' . $httpCode . ')' . PHP_EOL;
}

