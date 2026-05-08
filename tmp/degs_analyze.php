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


// ─────────────────────────────────────────────────────────────
// CERT LOAD + VALIDITY CHECK (NEW ADDED)
// ─────────────────────────────────────────────────────────────

$rawPem = file_get_contents($CERT_PATH);
if (!$rawPem) {
    die("❌ Cannot read certificate file\n");
}

$certData = openssl_x509_read($rawPem);
if (!$certData) {
    die("❌ Cannot parse certificate\n");
}

$certInfo = openssl_x509_parse($certData);

echo "Certificate subject : " . ($certInfo['subject']['CN'] ?? '-') . PHP_EOL;
echo "Valid from         : " . date('Y-m-d H:i:s', $certInfo['validFrom_time_t']) . PHP_EOL;
echo "Valid to           : " . date('Y-m-d H:i:s', $certInfo['validTo_time_t']) . PHP_EOL;

$now = time();

if ($now < $certInfo['validFrom_time_t']) {
    die("❌ Certificate NOT YET VALID\n");
}

if ($now > $certInfo['validTo_time_t']) {
    die("❌ Certificate EXPIRED\n");
}

echo "✅ Certificate is VALID\n\n";


// ── Certificate (continue original logic) ────────────────────────────────────

$certB64 = str_replace(
    ["\r", "\n", " "],
    '',
    preg_replace('/-----[^-]+-----/', '', $rawPem)
);

// Thumbprint = SHA1 of DER binary
$certDer       = base64_decode($certB64);
$thumbprintB64 = base64_encode(hash('sha1', $certDer, true));

// ── IDs / Timestamps ─────────────────────────────────────────────────────────
$bstId   = 'bst-' . bin2hex(random_bytes(8));
$msgId   = 'urn:uuid:' . uuid4();
$now     = gmdate('Y-m-d\TH:i:s\Z');
$expires = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

// ── 1. Envelope ──────────────────────────────────────────────────────────────

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


// ── Parse ────────────────────────────────────────────────────────────────────

$dom = new DOMDocument();
$dom->loadXML($rawXml);

$xpath = new DOMXPath($dom);
$xpath->registerNamespace('s',  $SOAP_NS);
$xpath->registerNamespace('u',  $WSU_NS);
$xpath->registerNamespace('o',  $WSSE_NS);
$xpath->registerNamespace('a',  $WSA_NS);
$xpath->registerNamespace('ds', $DSIG_NS);


// ── Nodes ────────────────────────────────────────────────────────────────────

$tsNode   = $xpath->query('//u:Timestamp')->item(0);
$bodyNode = $xpath->query('//s:Body')->item(0);


// ── Digests ──────────────────────────────────────────────────────────────────

$incPrefixes = ['s','a','u','o'];

$tsDigest   = base64_encode(hash('sha256', $tsNode->C14N(true,false,null,$incPrefixes), true));
$bodyDigest = base64_encode(hash('sha256', $bodyNode->C14N(true,false,null,$incPrefixes), true));

echo "TS digest: $tsDigest\n";
echo "Body digest: $bodyDigest\n";


// ── SignedInfo + Signature ──────────────────────────────────────────────────

$signedInfo = $dom->createElementNS($DSIG_NS, 'ds:SignedInfo');

$siCanon = $dom->createElementNS($DSIG_NS, 'ds:CanonicalizationMethod');
$siCanon->setAttribute('Algorithm','http://www.w3.org/2001/10/xml-exc-c14n#');

$signedInfo->appendChild($siCanon);

$siMethod = $dom->createElementNS($DSIG_NS, 'ds:SignatureMethod');
$siMethod->setAttribute('Algorithm','http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');

$signedInfo->appendChild($siMethod);


// Reference helper
$addRef = function($uri,$digest) use($dom,$signedInfo,$DSIG_NS) {
    $ref = $dom->createElementNS($DSIG_NS,'ds:Reference');
    $ref->setAttribute('URI',$uri);

    $dm = $dom->createElementNS($DSIG_NS,'ds:DigestMethod');
    $dm->setAttribute('Algorithm','http://www.w3.org/2001/04/xmlenc#sha256');

    $dv = $dom->createElementNS($DSIG_NS,'ds:DigestValue',$digest);

    $ref->appendChild($dm);
    $ref->appendChild($dv);

    $signedInfo->appendChild($ref);
};

$addRef('#_ts',$tsDigest);
$addRef('#_body',$bodyDigest);


// ── Sign ─────────────────────────────────────────────────────────────────────

$signatureNode = $dom->createElementNS($DSIG_NS,'ds:Signature');
$signatureNode->appendChild($signedInfo);

$dom->getElementsByTagName('Security')->item(0)->appendChild($signatureNode);

$signedInfoC14n = $signedInfo->C14N(true,false);

$privKey = openssl_pkey_get_private('file://'.$KEY_PATH);

openssl_sign($signedInfoC14n,$sig,$privKey,OPENSSL_ALGO_SHA256);

$signatureNode->appendChild(
    $dom->createElementNS($DSIG_NS,'ds:SignatureValue',base64_encode($sig))
);


// KeyInfo
$keyInfo = $dom->createElementNS($DSIG_NS,'ds:KeyInfo');
$str = $dom->createElementNS($WSSE_NS,'o:SecurityTokenReference');
$ki  = $dom->createElementNS($WSSE_NS,'o:KeyIdentifier',$thumbprintB64);
$ki->setAttribute('ValueType',$THUMB_VT);
$ki->setAttribute('EncodingType',$B64ET);

$str->appendChild($ki);
$keyInfo->appendChild($str);
$signatureNode->appendChild($keyInfo);


// ── Output ───────────────────────────────────────────────────────────────────

file_put_contents('/tmp/degs_v9.xml',$dom->saveXML());

echo "Saved /tmp/degs_v9.xml\n";


// ── Send ─────────────────────────────────────────────────────────────────────

$ch = curl_init($ENDPOINT);
curl_setopt_array($ch,[
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$dom->saveXML(),
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>[
        'Content-Type: application/soap+xml; charset=utf-8'
    ],
    CURLOPT_SSLCERT=>$CERT_PATH,
    CURLOPT_SSLKEY=>$KEY_PATH,
    CURLOPT_CAINFO=>$CA_PATH,
]);

echo curl_exec($ch);
curl_close($ch);


// ── UUID ─────────────────────────────────────────────────────────────────────

function uuid4(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0,0xffff),random_int(0,0xffff),
        random_int(0,0xffff),
        random_int(0x4000,0x4fff),
        random_int(0x8000,0xbfff),
        random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff)
    );
}
