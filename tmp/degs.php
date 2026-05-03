<?php

/**
 * php tmp/degs_wcf.php
 *
 * WCF-COMPATIBLE IsAlive test
 * WCF-ն ակնկালում է ՃԻՇՏ հետևյալ XML կառուցվածքը.
 * 1. BinarySecurityToken-ի վրա wsu:Id (ոչ u:Id)
 *    — BST-ն իր namespace-ն ունի wsse:, ոչ u:
 * 2. Signature-ն Security-ի ԱՄԵՆԱՎԵՐՋՈՒՄ (BST-ից ՀԵՏՈ)
 * 3. KeyInfo → STR → Reference URI="#bstId" — ճիշտ
 * 4. Timestamp u:Id="_0" (WCF-style id)
 * 5. Body u:Id="_1"
 */

require __DIR__ . '/../vendor/autoload.php';

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

$CERT_PATH = '/etc/ssl/degs/client.crt';
$KEY_PATH = '/etc/ssl/degs/client.key';
$CA_PATH = '/etc/ssl/certs/DEGSTESTRootCA.pem';
$ENDPOINT = 'https://100.100.100.60:8888/DEGSHost';
$ACTION = 'http://tempuri.org/IDegsNSS/IsAlive';

$SOAP_NS = 'http://www.w3.org/2003/05/soap-envelope';
$WSA_NS = 'http://www.w3.org/2005/08/addressing';
$WSU_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
$WSSE_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$X509VT = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
$B64ET = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';
$DSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';

// Print cert subject
$certPem = file_get_contents($CERT_PATH);
$certData = openssl_x509_parse($certPem);
echo "=== CERT SUBJECT ===\n";
foreach ((array)($certData['subject'] ?? []) as $k => $v) {
    echo "  $k = $v\n";
}
echo "=== CERT EXTENSIONS ===\n";
foreach ((array)($certData['extensions'] ?? []) as $k => $v) {
    if (!is_array($v)) echo "  $k = $v\n";
}

// Cert → clean DER → base64
openssl_x509_export(openssl_x509_read($certPem), $cleanPem);
$certDer = base64_decode(preg_replace('/-----[^-]+-----|[\r\n\s]/', '', $cleanPem));
$certB64 = base64_encode($certDer);

$created = gmdate('Y-m-d\TH:i:s\Z');
$expires = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
$msgId = 'urn:uuid:' . gen_uuid();

// BST Id — WCF style
$bstId = 'uuid-' . gen_uuid() . '-1';

// ============================================================
// APPROACH A — WCF exact style
// wsu:Id on EVERY signed element, with wsu prefix declared
// directly ON that element (not just on Envelope)
// ============================================================
$envelope = '<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="' . $SOAP_NS . '" xmlns:a="' . $WSA_NS . '">
  <s:Header>
    <a:Action s:mustUnderstand="1">' . $ACTION . '</a:Action>
    <a:MessageID>' . $msgId . '</a:MessageID>
    <a:To s:mustUnderstand="1">' . $ENDPOINT . '</a:To>
    <o:Security s:mustUnderstand="1"
        xmlns:o="' . $WSSE_NS . '">
      <u:Timestamp wsu:Id="_0"
          xmlns:u="' . $WSU_NS . '"
          xmlns:wsu="' . $WSU_NS . '">
        <u:Created>' . $created . '</u:Created>
        <u:Expires>' . $expires . '</u:Expires>
      </u:Timestamp>
      <o:BinarySecurityToken
          wsu:Id="' . $bstId . '"
          ValueType="' . $X509VT . '"
          EncodingType="' . $B64ET . '"
          xmlns:wsu="' . $WSU_NS . '">' . $certB64 . '</o:BinarySecurityToken>
    </o:Security>
  </s:Header>
  <s:Body wsu:Id="_1" xmlns:wsu="' . $WSU_NS . '">
    <IsAlive xmlns="http://tempuri.org/"/>
  </s:Body>
</s:Envelope>';

$dom = new DOMDocument();
$dom->preserveWhiteSpace = false;
$dom->loadXML($envelope);

$xp = new DOMXPath($dom);
$xp->registerNamespace('wsu', $WSU_NS);
$xp->registerNamespace('o', $WSSE_NS);
$xp->registerNamespace('s', $SOAP_NS);

// Register ALL wsu:Id attributes for getElementById
foreach ($xp->query('//*[@wsu:Id]') as $node) {
    $node->setIdAttributeNS($WSU_NS, 'Id', true);
}

// Verify getElementById works
$ts = $dom->getElementById('_0');
$body = $dom->getElementById('_1');
echo "\n=== getElementById check ===\n";
echo "  _0 (Timestamp) : " . ($ts ? $ts->localName . ' ✓' : 'NULL ❌') . "\n";
echo "  _1 (Body)      : " . ($body ? $body->localName . ' ✓' : 'NULL ❌') . "\n";

// Sign
$dsig = new XMLSecurityDSig('');
$dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

$refOpts = [
    'id_name' => 'Id',
    'prefix' => 'wsu',
    'prefix_ns' => $WSU_NS,
    'overwrite' => false,
];
$dsig->addReference($dom, XMLSecurityDSig::SHA256,
    [XMLSecurityDSig::EXC_C14N],
    array_merge($refOpts, ['uri' => '#_0']));
$dsig->addReference($dom, XMLSecurityDSig::SHA256,
    [XMLSecurityDSig::EXC_C14N],
    array_merge($refOpts, ['uri' => '#_1']));

$objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
$objKey->loadKey($KEY_PATH, true);
$dsig->sign($objKey);

$secNode = $xp->query('//o:Security')->item(0);
$dsig->appendSignature($secNode);

// KeyInfo
$sigNode = $secNode->getElementsByTagNameNS($DSIG_NS, 'Signature')->item(0);
$keyInfo = $dom->createElementNS($DSIG_NS, 'ds:KeyInfo');
$strEl = $dom->createElementNS($WSSE_NS, 'o:SecurityTokenReference');
$refEl = $dom->createElementNS($WSSE_NS, 'o:Reference');
$refEl->setAttribute('URI', '#' . $bstId);
$refEl->setAttribute('ValueType', $X509VT);
$strEl->appendChild($refEl);
$keyInfo->appendChild($strEl);
$sigNode->appendChild($keyInfo);

$signed = $dom->saveXML();
file_put_contents('/tmp/signed_wcf.xml', $signed);
echo "\nSigned XML saved: /tmp/signed_wcf.xml\n";

// Self-verify
echo "\n=== Self-verify ===\n";
$vDom = new DOMDocument();
$vDom->preserveWhiteSpace = false;
$vDom->loadXML($signed);
$vxp = new DOMXPath($vDom);
$vxp->registerNamespace('wsu', $WSU_NS);
foreach ($vxp->query('//*[@wsu:Id]') as $n) {
    $n->setIdAttributeNS($WSU_NS, 'Id', true);
}
$objV = new XMLSecurityDSig();
$objV->locateSignature($vDom);
$objV->canonicalizeSignedInfo();
try {
    $pub = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'public']);
    $pub->loadKey($CERT_PATH, true, true);
    echo "  verify()          : " . ($objV->verify($pub) === 1 ? 'OK ✓' : 'FAIL ❌') . "\n";
    echo "  validateReference : " . ($objV->validateReference() ? 'OK ✓' : 'FAIL ❌') . "\n";
} catch (Exception $e) {
    echo "  Exception: " . $e->getMessage() . "\n";
}

// Send
echo "\n=== Sending ===\n";
$vlog = fopen('php://temp', 'w+');
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $ENDPOINT,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $signed,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/soap+xml; charset=utf-8; action="' . $ACTION . '"',
    ],
    CURLOPT_SSLCERT => $CERT_PATH,
    CURLOPT_SSLKEY => $KEY_PATH,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_CAINFO => $CA_PATH,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_VERBOSE => true,
    CURLOPT_STDERR => $vlog,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "HTTP: $code\n";
echo "cURL: " . ($err ?: 'none') . "\n";
echo "Response:\n$resp\n";

// Also print the raw signed XML structure for debugging
echo "\n=== Security node content (structure) ===\n";
$dx = new DOMDocument();
$dx->preserveWhiteSpace = false;
$dx->formatOutput = true;
$dx->loadXML($signed);
$dxp = new DOMXPath($dx);
$dxp->registerNamespace('o', $WSSE_NS);
$sec = $dxp->query('//o:Security')->item(0);
if ($sec) {
    foreach ($sec->childNodes as $ch) {
        if ($ch->nodeType === XML_ELEMENT_NODE) {
            echo "  <{$ch->localName}";
            foreach ($ch->attributes as $attr) {
                if (!in_array($attr->localName, ['EncodingType', 'ValueType'])) {
                    echo " {$attr->name}='{$attr->value}'";
                }
            }
            echo ">\n";
        }
    }
}

function gen_uuid(): string
{
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff));
}
