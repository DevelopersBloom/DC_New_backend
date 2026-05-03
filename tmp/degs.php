<?php
require '/var/www/html/test-diamond-credit/vendor/autoload.php';

use RobRichards\XMLSecLibs\XMLSecurityKey;

$certPath  = '/etc/ssl/degs/client.crt';
$keyPath   = '/etc/ssl/degs/client.key';
$caPath    = '/etc/ssl/certs/DEGSTESTRootCA.pem';
$endpoint  = 'https://100.100.100.60:8888/DEGSHost';
$actionUrl = 'http://tempuri.org/IDegsNSS/IsAlive';

$secNs  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$wsuNs  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
$dsigNs = 'http://www.w3.org/2000/09/xmldsig#';
$soapNs = 'http://www.w3.org/2003/05/soap-envelope';
$addrNs = 'http://www.w3.org/2005/08/addressing';

function makeUuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
}

function sha256b64(string $data): string {
    return base64_encode(hash('sha256', $data, true));
}

function rsaSign(string $data, string $keyPath): string {
    $key = openssl_pkey_get_private(file_get_contents($keyPath));
    openssl_sign($data, $sig, $key, OPENSSL_ALGO_SHA256);
    return base64_encode($sig);
}

/**
 * Canonicalize a node with its full namespace context from the document root.
 * This is what XML-DSIG requires: inherited namespaces must appear in C14N output.
 */
function c14nNode(\DOMNode $node): string {
    // Exc-C14N with empty inclusive prefixes list — only visibly utilized namespaces
    return $node->C14N(true, false, null, []);
}

// ─── IDs ──────────────────────────────────────────────────────────────────────
$msgId  = 'urn:uuid:' . makeUuid();
$tsId   = 'TS-'   . strtoupper(str_replace('-','',makeUuid()));
$toId   = 'TO-'   . strtoupper(str_replace('-','',makeUuid()));
$bodyId = 'BODY-' . strtoupper(str_replace('-','',makeUuid()));
$bstId  = 'BST-'  . strtoupper(str_replace('-','',makeUuid()));

$now = gmdate('Y-m-d\TH:i:s\Z');
$exp = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

// ─── Certificate ──────────────────────────────────────────────────────────────
$certPem = file_get_contents($certPath);
$certDer = base64_decode(str_replace(
    ['-----BEGIN CERTIFICATE-----','-----END CERTIFICATE-----',"\n","\r",' '],
    '', $certPem
));
$certB64    = base64_encode($certDer);
$thumbprint = base64_encode(hash('sha1', $certDer, true));

// ─── Build envelope ───────────────────────────────────────────────────────────
// IMPORTANT: All namespaces declared on root Envelope so C14N propagates correctly
$envelope = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<s:Envelope'
    .     ' xmlns:s="'  . $soapNs . '"'
    .     ' xmlns:a="'  . $addrNs . '"'
    .     ' xmlns:u="'  . $wsuNs  . '"'
    .     ' xmlns:o="'  . $secNs  . '"'
    .     ' xmlns:ds="' . $dsigNs . '">'
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

// ─── Parse DOM ────────────────────────────────────────────────────────────────
$dom = new DOMDocument();
$dom->loadXML($envelope);

$xpath = new DOMXPath($dom);
$xpath->registerNamespace('s',  $soapNs);
$xpath->registerNamespace('a',  $addrNs);
$xpath->registerNamespace('u',  $wsuNs);
$xpath->registerNamespace('o',  $secNs);
$xpath->registerNamespace('ds', $dsigNs);

$tsNode   = $xpath->query('//*[@u:Id="' . $tsId . '"]')->item(0);
$toNode   = $xpath->query('//*[@u:Id="' . $toId . '"]')->item(0);
$bodyNode = $xpath->query('//*[@u:Id="' . $bodyId . '"]')->item(0);

if (!$tsNode || !$toNode || !$bodyNode) {
    die("ERROR: Could not find nodes\n");
}

// ─── Canonicalize nodes ───────────────────────────────────────────────────────
$tsC14N   = c14nNode($tsNode);
$toC14N   = c14nNode($toNode);
$bodyC14N = c14nNode($bodyNode);

echo "C14N outputs:\n";
echo "  TS   ($tsId):\n$tsC14N\n\n";
echo "  TO   ($toId):\n$toC14N\n\n";
echo "  BODY ($bodyId):\n$bodyC14N\n\n";

$tsDigest   = sha256b64($tsC14N);
$toDigest   = sha256b64($toC14N);
$bodyDigest = sha256b64($bodyC14N);

echo "Digests:\n  ts=$tsDigest\n  to=$toDigest\n  body=$bodyDigest\n\n";

// ─── Build SignedInfo ─────────────────────────────────────────────────────────
$c14nAlg   = 'http://www.w3.org/2001/10/xml-exc-c14n#';
$sigAlg    = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
$digestAlg = 'http://www.w3.org/2001/04/xmlenc#sha256';

$signedInfo =
    '<ds:SignedInfo xmlns:ds="' . $dsigNs . '">'
    .   '<ds:CanonicalizationMethod Algorithm="' . $c14nAlg . '"/>'
    .   '<ds:SignatureMethod Algorithm="' . $sigAlg . '"/>'
    .   '<ds:Reference URI="#' . $tsId . '">'
    .     '<ds:Transforms><ds:Transform Algorithm="' . $c14nAlg . '"/></ds:Transforms>'
    .     '<ds:DigestMethod Algorithm="' . $digestAlg . '"/>'
    .     '<ds:DigestValue>' . $tsDigest . '</ds:DigestValue>'
    .   '</ds:Reference>'
    .   '<ds:Reference URI="#' . $toId . '">'
    .     '<ds:Transforms><ds:Transform Algorithm="' . $c14nAlg . '"/></ds:Transforms>'
    .     '<ds:DigestMethod Algorithm="' . $digestAlg . '"/>'
    .     '<ds:DigestValue>' . $toDigest . '</ds:DigestValue>'
    .   '</ds:Reference>'
    .   '<ds:Reference URI="#' . $bodyId . '">'
    .     '<ds:Transforms><ds:Transform Algorithm="' . $c14nAlg . '"/></ds:Transforms>'
    .     '<ds:DigestMethod Algorithm="' . $digestAlg . '"/>'
    .     '<ds:DigestValue>' . $bodyDigest . '</ds:DigestValue>'
    .   '</ds:Reference>'
    . '</ds:SignedInfo>';

// Canonicalize SignedInfo before signing
$siDom = new DOMDocument();
$siDom->loadXML($signedInfo);
$siC14N = $siDom->documentElement->C14N(true, false);

echo "SignedInfo C14N:\n$siC14N\n\n";

$sigValue = rsaSign($siC14N, $keyPath);

// ─── Build Signature element ──────────────────────────────────────────────────
$signatureXml =
    '<ds:Signature xmlns:ds="' . $dsigNs . '">'
    . $signedInfo
    . '<ds:SignatureValue>' . $sigValue . '</ds:SignatureValue>'
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

// Insert into Security node
$sigDom  = new DOMDocument();
$sigDom->loadXML($signatureXml);
$sigNode = $dom->importNode($sigDom->documentElement, true);
$secNode = $dom->getElementsByTagNameNS($secNs, 'Security')->item(0);
$secNode->appendChild($sigNode);

$finalXml = $dom->saveXML();
file_put_contents('/tmp/signed_ns.xml', $finalXml);
echo "Saved: /tmp/signed_ns.xml\n\n";

// ─── Send ─────────────────────────────────────────────────────────────────────
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
if ($err) echo "cURL: $err\n";
echo "Response: $response\n";
