<?php
require '/var/www/html/test-diamond-credit/vendor/autoload.php';

use RobRichards\XMLSecLibs\XMLSecurityKey;

$certPath = '/etc/ssl/degs/client.crt';
$keyPath  = '/etc/ssl/degs/client.key';
$caPath   = '/etc/ssl/certs/DEGSTESTRootCA.pem';
$endpoint = 'https://100.100.100.60:8888/DEGSHost';
$actionUrl = 'http://tempuri.org/IDegsNSS/IsAlive';

function makeUuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
}

/**
 * Exc-C14N canonicalize a DOM node
 */
function excC14N(\DOMNode $node): string {
    $doc = new DOMDocument();
    $doc->appendChild($doc->importNode($node, true));
    // Use C14N with exclusive flag
    return $node->C14N(true, false);
}

/**
 * SHA-256 digest of a string, base64 encoded
 */
function sha256b64(string $data): string {
    return base64_encode(hash('sha256', $data, true));
}

/**
 * Sign data with RSA-SHA256 private key, return base64
 */
function rsaSha256Sign(string $data, string $keyPath): string {
    $privKey = openssl_pkey_get_private(file_get_contents($keyPath));
    openssl_sign($data, $signature, $privKey, OPENSSL_ALGO_SHA256);
    return base64_encode($signature);
}

// ─── IDs ──────────────────────────────────────────────────────────────────────
$msgId  = 'urn:uuid:' . makeUuid();
$tsId   = '_ts_' . str_replace('-', '', makeUuid());
$toId   = '_to_' . str_replace('-', '', makeUuid());
$bodyId = '_body_' . str_replace('-', '', makeUuid());
$bstId  = '_bst_' . str_replace('-', '', makeUuid());
$sigId  = '_sig_' . str_replace('-', '', makeUuid());

$now = gmdate('Y-m-d\TH:i:s\Z');
$exp = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

$secNs  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$wsuNs  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
$dsigNs = 'http://www.w3.org/2000/09/xmldsig#';
$soapNs = 'http://www.w3.org/2003/05/soap-envelope';
$addrNs = 'http://www.w3.org/2005/08/addressing';

// ─── Certificate ──────────────────────────────────────────────────────────────
$certPem = file_get_contents($certPath);
$certDer = base64_decode(str_replace(
    ['-----BEGIN CERTIFICATE-----','-----END CERTIFICATE-----',"\n","\r",' '],
    '', $certPem
));
$certB64    = base64_encode($certDer);
$thumbprint = base64_encode(hash('sha1', $certDer, true));

echo "Thumbprint: $thumbprint\n";

// ─── Step 1: Build envelope WITHOUT signature ─────────────────────────────────
$envelope = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<s:Envelope'
    .     ' xmlns:s="' . $soapNs . '"'
    .     ' xmlns:a="' . $addrNs . '"'
    .     ' xmlns:u="' . $wsuNs . '"'
    .     ' xmlns:o="' . $secNs . '">'
    .   '<s:Header>'
    .     '<a:Action s:mustUnderstand="1">' . $actionUrl . '</a:Action>'
    .     '<a:MessageID>' . $msgId . '</a:MessageID>'
    .     '<a:To s:mustUnderstand="1" u:Id="' . $toId . '">' . $endpoint . '</a:To>'
    .     '<o:Security s:mustUnderstand="1">'
    .       '<u:Timestamp u:Id="' . $tsId . '">'
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
    .   '<s:Body u:Id="' . $bodyId . '">'
    .     '<tns:IsAlive xmlns:tns="http://tempuri.org/"/>'
    .   '</s:Body>'
    . '</s:Envelope>';

// ─── Step 2: Parse and canonicalize each node to sign ─────────────────────────
$dom = new DOMDocument();
$dom->loadXML($envelope);

$xpath = new DOMXPath($dom);
$xpath->registerNamespace('s', $soapNs);
$xpath->registerNamespace('a', $addrNs);
$xpath->registerNamespace('u', $wsuNs);
$xpath->registerNamespace('o', $secNs);

// Find nodes by their u:Id attribute value using XPath (not getElementById)
$tsNode   = $xpath->query('//*[@u:Id="' . $tsId . '"]')->item(0);
$toNode   = $xpath->query('//*[@u:Id="' . $toId . '"]')->item(0);
$bodyNode = $xpath->query('//*[@u:Id="' . $bodyId . '"]')->item(0);

echo "XPath node check:\n";
echo '  Timestamp: ' . ($tsNode   ? $tsNode->nodeName   : 'NOT FOUND') . "\n";
echo '  To:        ' . ($toNode   ? $toNode->nodeName   : 'NOT FOUND') . "\n";
echo '  Body:      ' . ($bodyNode ? $bodyNode->nodeName : 'NOT FOUND') . "\n\n";

if (!$tsNode || !$toNode || !$bodyNode) {
    die("ERROR: Could not find nodes to sign\n");
}

// Canonicalize each node (Exclusive C14N)
$tsC14N   = $tsNode->C14N(true, false);
$toC14N   = $toNode->C14N(true, false);
$bodyC14N = $bodyNode->C14N(true, false);

echo "C14N lengths: ts=" . strlen($tsC14N) . " to=" . strlen($toC14N) . " body=" . strlen($bodyC14N) . "\n\n";

// Compute digests
$tsDigest   = sha256b64($tsC14N);
$toDigest   = sha256b64($toC14N);
$bodyDigest = sha256b64($bodyC14N);

echo "Digests:\n  ts=$tsDigest\n  to=$toDigest\n  body=$bodyDigest\n\n";

// ─── Step 3: Build SignedInfo ─────────────────────────────────────────────────
$c14nMethod = 'http://www.w3.org/2001/10/xml-exc-c14n#';
$sigMethod  = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
$digestMeth = 'http://www.w3.org/2001/04/xmlenc#sha256';

$signedInfo = '<ds:SignedInfo xmlns:ds="' . $dsigNs . '">'
    . '<ds:CanonicalizationMethod Algorithm="' . $c14nMethod . '"/>'
    . '<ds:SignatureMethod Algorithm="' . $sigMethod . '"/>'
    . '<ds:Reference URI="#' . $tsId . '">'
    .   '<ds:Transforms>'
    .     '<ds:Transform Algorithm="' . $c14nMethod . '"/>'
    .   '</ds:Transforms>'
    .   '<ds:DigestMethod Algorithm="' . $digestMeth . '"/>'
    .   '<ds:DigestValue>' . $tsDigest . '</ds:DigestValue>'
    . '</ds:Reference>'
    . '<ds:Reference URI="#' . $toId . '">'
    .   '<ds:Transforms>'
    .     '<ds:Transform Algorithm="' . $c14nMethod . '"/>'
    .   '</ds:Transforms>'
    .   '<ds:DigestMethod Algorithm="' . $digestMeth . '"/>'
    .   '<ds:DigestValue>' . $toDigest . '</ds:DigestValue>'
    . '</ds:Reference>'
    . '<ds:Reference URI="#' . $bodyId . '">'
    .   '<ds:Transforms>'
    .     '<ds:Transform Algorithm="' . $c14nMethod . '"/>'
    .   '</ds:Transforms>'
    .   '<ds:DigestMethod Algorithm="' . $digestMeth . '"/>'
    .   '<ds:DigestValue>' . $bodyDigest . '</ds:DigestValue>'
    . '</ds:Reference>'
    . '</ds:SignedInfo>';

// ─── Step 4: Canonicalize SignedInfo and sign ─────────────────────────────────
$siDom = new DOMDocument();
$siDom->loadXML($signedInfo);
$siC14N = $siDom->documentElement->C14N(true, false);

$signatureValue = rsaSha256Sign($siC14N, $keyPath);
echo "SignatureValue (first 40): " . substr($signatureValue, 0, 40) . "...\n\n";

// ─── Step 5: Build full Signature element ─────────────────────────────────────
$signatureXml = '<ds:Signature xmlns:ds="' . $dsigNs . '" Id="' . $sigId . '">'
    . $signedInfo
    . '<ds:SignatureValue>' . $signatureValue . '</ds:SignatureValue>'
    . '<ds:KeyInfo>'
    .   '<o:SecurityTokenReference xmlns:o="' . $secNs . '">'
    .     '<o:KeyIdentifier'
    .         ' ValueType="http://docs.oasis-open.org/wss/oasis-wss-soap-message-security-1.1#ThumbprintSHA1"'
    .         ' EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">'
    .       $thumbprint
    .     '</o:KeyIdentifier>'
    .   '</o:SecurityTokenReference>'
    . '</ds:KeyInfo>'
    . '</ds:Signature>';

// ─── Step 6: Insert Signature into Security node ──────────────────────────────
$sigDom = new DOMDocument();
$sigDom->loadXML($signatureXml);
$sigNode = $dom->importNode($sigDom->documentElement, true);

$secNode = $dom->getElementsByTagNameNS($secNs, 'Security')->item(0);
$secNode->appendChild($sigNode);

$finalXml = $dom->saveXML();

file_put_contents('/tmp/signed_manual.xml', $finalXml);
echo "Saved: /tmp/signed_manual.xml\n";

// Quick check
$checkDom = new DOMDocument();
$checkDom->loadXML($finalXml);
$refs = $checkDom->getElementsByTagNameNS($dsigNs, 'Reference');
echo "References in final XML: " . $refs->length . "\n";
foreach ($refs as $r) {
    echo "  URI=" . $r->getAttribute('URI') . "\n";
}

// ─── Step 7: Send ─────────────────────────────────────────────────────────────
echo "\nSending...\n";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $endpoint,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $finalXml,
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
$err      = curl_error($ch);
curl_close($ch);

echo "HTTP: $httpCode\n";
if ($err) echo "cURL Error: $err\n";
echo "Response: $response\n";
