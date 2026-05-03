<?php
/**
 * php tmp/degs_final.php
 *
 * ՎԵՐՋՆԱԿԱՆ ՈՒՂՂՈՒՄ
 *
 * Խնդիր էր՝
 *   validateReference=OK բայց verify()=FAIL
 *   → SignedInfo canonicalization mismatch
 *
 * Պատճառ՝
 *   wsu:Id-ն DOMDocument parse-ից հետո Id (plain) է դառնում,
 *   canonicalization-ի ժամանակ namespace declarations-ը
 *   SignedInfo-ի մեջ ավելանում են, բայց verify-ի ժամանակ
 *   DOM-ը դրանք վերօrganize-ի → SignatureValue mismatch։
 *
 * Լուծում՝
 *   XMLSecLib-ն օգտագործի ՆՈՒՅՆ DOM-ն sign-ի ու verify-ի համար,
 *   prefix-ները stable պահել, namespace-ները Envelope root-ում
 *   centralize, wsu: prefix-ն ԱՄԵՆՈՒՐ ՆՈՒՅՆ NAMESPACE-ով,
 *   և sign-ից ՀԵՏՈ XML-ն serialize → re-parse ՉԱՆԵԼ verify-ի ժամանակ։
 *
 *   Հիմնական fix՝ u: prefix = wsu: namespace ԱՄԵՆՈՒՐ (ոչ wsu: prefix),
 *   EXC_C14N inclusive namespaces list-ն ճիշտ set անել։
 */

require __DIR__ . '/../vendor/autoload.php';

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

$CERT_PATH = '/etc/ssl/degs/client.crt';
$KEY_PATH  = '/etc/ssl/degs/client.key';
$CA_PATH   = '/etc/ssl/certs/DEGSTESTRootCA.pem';
$ENDPOINT  = 'https://100.100.100.60:8888/DEGSHost';

$SOAP_NS = 'http://www.w3.org/2003/05/soap-envelope';
$WSA_NS  = 'http://www.w3.org/2005/08/addressing';
$WSU_NS  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
$WSSE_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$X509VT  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
$B64ET   = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';
$DSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';

function gen_uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0,0xffff), random_int(0,0xffff), random_int(0,0xffff),
        random_int(0,0x0fff)|0x4000, random_int(0,0x3fff)|0x8000,
        random_int(0,0xffff), random_int(0,0xffff), random_int(0,0xffff));
}

function send_soap(string $xml, string $action, string $cert, string $key, string $ca, string $endpoint): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/soap+xml; charset=utf-8; action="'.$action.'"',
        ],
        CURLOPT_SSLCERT        => $cert,
        CURLOPT_SSLKEY         => $key,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO         => $ca,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$resp, 'err' => $err];
}

/**
 * Կառուցել signed SOAP envelope
 *
 * KEY INSIGHT:
 * DOMDocument-ն load-ից հետո namespace prefix-ները կարող է
 * փոխել կամ merge անել։ XMLSecLib EXC_C14N-ն sign-ի ժամանակ
 * serialize-ում է prefix inclusions-ով։
 *
 * Ճիշտ approach — DOMDocument-ն ստեղծել programmatically
 * (createElementNS), ոչ թե string parse անել, որ prefix-ները
 * stable մնան DOM-ի internal representation-ում։
 */
function build_and_sign(
    string $bodyXml,
    string $soapAction,
    string $certPath,
    string $keyPath,
    string $endpoint
): string {
    $SOAP_NS = 'http://www.w3.org/2003/05/soap-envelope';
    $WSA_NS  = 'http://www.w3.org/2005/08/addressing';
    $WSU_NS  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    $WSSE_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    $X509VT  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
    $B64ET   = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';
    $DSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';

    $tsId    = '_0';
    $bodyId  = '_1';
    $bstId   = 'uuid-' . gen_uuid() . '-1';
    $msgId   = 'urn:uuid:' . gen_uuid();
    $created = gmdate('Y-m-d\TH:i:s\Z');
    $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

    // Cert PEM → DER → Base64
    $rawPem = file_get_contents($certPath);
    openssl_x509_export(openssl_x509_read($rawPem), $cleanPem);
    $certDer = base64_decode(preg_replace('/-----[^-]+-----|[\r\n\s]/', '', $cleanPem));
    $certB64 = base64_encode($certDer);

    // ----------------------------------------------------------------
    // DOM ստեղծել programmatically — prefix-ները stable են մնում
    // ----------------------------------------------------------------
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;

    // <s:Envelope>
    $envelope = $dom->createElementNS($SOAP_NS, 's:Envelope');
    $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:a',   $WSA_NS);
    $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:u',   $WSU_NS);
    $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:o',   $WSSE_NS);
    $dom->appendChild($envelope);

    // <s:Header>
    $header = $dom->createElementNS($SOAP_NS, 's:Header');
    $envelope->appendChild($header);

    // <a:Action>
    $action = $dom->createElementNS($WSA_NS, 'a:Action', $soapAction);
    $action->setAttributeNS($SOAP_NS, 's:mustUnderstand', '1');
    $header->appendChild($action);

    // <a:MessageID>
    $mid = $dom->createElementNS($WSA_NS, 'a:MessageID', $msgId);
    $header->appendChild($mid);

    // <a:To>
    $to = $dom->createElementNS($WSA_NS, 'a:To', $endpoint);
    $to->setAttributeNS($SOAP_NS, 's:mustUnderstand', '1');
    $header->appendChild($to);

    // <o:Security>
    $security = $dom->createElementNS($WSSE_NS, 'o:Security');
    $security->setAttributeNS($SOAP_NS, 's:mustUnderstand', '1');
    $header->appendChild($security);

    // <u:Timestamp u:Id="_0">
    $timestamp = $dom->createElementNS($WSU_NS, 'u:Timestamp');
    $timestamp->setAttributeNS($WSU_NS, 'u:Id', $tsId);
    $timestamp->setIdAttributeNS($WSU_NS, 'Id', true);
    $timestamp->appendChild($dom->createElementNS($WSU_NS, 'u:Created', $created));
    $timestamp->appendChild($dom->createElementNS($WSU_NS, 'u:Expires', $expires));
    $security->appendChild($timestamp);

    // <o:BinarySecurityToken u:Id="...">
    $bst = $dom->createElementNS($WSSE_NS, 'o:BinarySecurityToken', $certB64);
    $bst->setAttributeNS($WSU_NS, 'u:Id', $bstId);
    $bst->setIdAttributeNS($WSU_NS, 'Id', true);
    $bst->setAttribute('ValueType',    $X509VT);
    $bst->setAttribute('EncodingType', $B64ET);
    $security->appendChild($bst);

    // <s:Body u:Id="_1">
    $body = $dom->createElementNS($SOAP_NS, 's:Body');
    $body->setAttributeNS($WSU_NS, 'u:Id', $bodyId);
    $body->setIdAttributeNS($WSU_NS, 'Id', true);
    $envelope->appendChild($body);

    // Body content inject
    $bodyDom = new DOMDocument();
    $bodyDom->loadXML('<root>' . $bodyXml . '</root>');
    foreach ($bodyDom->documentElement->childNodes as $child) {
        $body->appendChild($dom->importNode($child, true));
    }

    // ----------------------------------------------------------------
    // Ստորագրություն
    // ----------------------------------------------------------------

    // Verify getElementById works (DOM programmatic → guaranteed)
    $checkTs   = $dom->getElementById($tsId);
    $checkBody = $dom->getElementById($bodyId);
    echo "getElementById check: ts=" . ($checkTs   ? '✓' : '❌NULL') .
        ' body=' . ($checkBody ? '✓' : '❌NULL') . "\n";

    $dsig = new XMLSecurityDSig('');
    $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

    $refOpts = [
        'id_name'   => 'Id',
        'prefix'    => 'u',
        'prefix_ns' => $WSU_NS,
        'overwrite' => false,
    ];
    $dsig->addReference($dom, XMLSecurityDSig::SHA256,
        [XMLSecurityDSig::EXC_C14N],
        array_merge($refOpts, ['uri' => '#' . $tsId]));
    $dsig->addReference($dom, XMLSecurityDSig::SHA256,
        [XMLSecurityDSig::EXC_C14N],
        array_merge($refOpts, ['uri' => '#' . $bodyId]));

    $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
    $objKey->loadKey($keyPath, true);
    $dsig->sign($objKey);
    $dsig->appendSignature($security);

    // KeyInfo → SecurityTokenReference
    $sigNode = $security->getElementsByTagNameNS($DSIG_NS, 'Signature')->item(0);
    $keyInfo = $dom->createElementNS($DSIG_NS, 'ds:KeyInfo');
    $strEl   = $dom->createElementNS($WSSE_NS, 'o:SecurityTokenReference');
    $refEl   = $dom->createElementNS($WSSE_NS, 'o:Reference');
    $refEl->setAttribute('URI',       '#' . $bstId);
    $refEl->setAttribute('ValueType', $X509VT);
    $strEl->appendChild($refEl);
    $keyInfo->appendChild($strEl);
    $sigNode->appendChild($keyInfo);

    // ----------------------------------------------------------------
    // Self-verify ON THE SAME DOM (no re-parse!)
    // ----------------------------------------------------------------
    $objV = new XMLSecurityDSig();
    $objV->locateSignature($dom);
    $objV->canonicalizeSignedInfo();
    try {
        $pub = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'public']);
        $pub->loadKey($certPath, true, true);
        $vr  = $objV->verify($pub);
        $vrr = $objV->validateReference();
        echo "Self-verify (same DOM): verify=" . ($vr===1?'OK ✓':'FAIL ❌(code:'.$vr.')')
            . " validateRef=" . ($vrr?'OK ✓':'FAIL ❌') . "\n";
    } catch (Exception $e) {
        echo "Self-verify exception: " . $e->getMessage() . "\n";
    }

    return $dom->saveXML();
}

// ============================================================
// TEST IsAlive
// ============================================================
echo "=== IsAlive ===\n";
$xml = build_and_sign(
    '<IsAlive xmlns="http://tempuri.org/"/>',
    'http://tempuri.org/IDegsNSS/IsAlive',
    $CERT_PATH, $KEY_PATH, $ENDPOINT
);
file_put_contents('/tmp/signed_final.xml', $xml);
echo "Saved: /tmp/signed_final.xml\n";

$r = send_soap($xml, 'http://tempuri.org/IDegsNSS/IsAlive',
    $CERT_PATH, $KEY_PATH, $CA_PATH, $ENDPOINT);
echo "HTTP: {$r['code']}\n";
echo "Response: {$r['body']}\n";
