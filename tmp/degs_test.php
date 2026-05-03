<?php
/**
 * php tmp/degs_cert_and_send.php
 *
 * 1. Cert-ի ամբողջ subject/extension տվյալները print
 * 2. IsAlive ուղարկել ՃԻՇՏ OrganisationCode-ով (cert-ից)
 * 3. SendRequest IsDelay=true (dry-run) ուղարկել test L001 XML-ով
 */

require __DIR__ . '/../vendor/autoload.php';

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

$CERT_PATH = '/etc/ssl/degs/client.crt';
$KEY_PATH  = '/etc/ssl/degs/client.key';
$CA_PATH   = '/etc/ssl/certs/DEGSTESTRootCA.pem';
$ENDPOINT  = 'https://100.100.100.60:8888/DEGSHost';

$WSU_NS  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
$WSSE_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
$SOAP_NS = 'http://www.w3.org/2003/05/soap-envelope';
$WSA_NS  = 'http://www.w3.org/2005/08/addressing';
$X509VT  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
$B64ET   = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';
$DSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';

// ============================================================
// 1. FULL CERT DETAILS
// ============================================================
echo "=== FULL CERTIFICATE DETAILS ===\n";
$certPem  = file_get_contents($CERT_PATH);
$certData = openssl_x509_parse($certPem);

echo "\n-- Subject fields --\n";
foreach ((array)($certData['subject'] ?? []) as $k => $v) {
    echo "  $k = $v\n";
}

echo "\n-- Issuer fields --\n";
foreach ((array)($certData['issuer'] ?? []) as $k => $v) {
    echo "  $k = $v\n";
}

echo "\n-- Extensions --\n";
foreach ((array)($certData['extensions'] ?? []) as $k => $v) {
    echo "  $k = " . (is_array($v) ? implode(', ', $v) : $v) . "\n";
}

echo "\n-- subjectAltName (raw) --\n";
echo "  " . ($certData['extensions']['subjectAltName'] ?? 'none') . "\n";

// Extract ORG_CODE candidate from CN or OU
$cn = $certData['subject']['CN'] ?? '';
$ou = $certData['subject']['OU'] ?? '';
$o  = $certData['subject']['O']  ?? '';
echo "\n-- CN='$cn'  OU='$ou'  O='$o' --\n";

// Try to find 5-digit code in subject fields
$orgCode = null;
foreach ([$cn, $ou, $o] as $field) {
    if (preg_match('/\b(\d{5})\b/', $field, $m)) {
        $orgCode = $m[1];
        echo "  ➜ Found 5-digit ORG_CODE in subject: '$orgCode'\n";
        break;
    }
}
if (!$orgCode) {
    echo "  ⚠ No 5-digit code found in subject — using 66100 as fallback\n";
    $orgCode = '66100';
}

// ============================================================
// 2. HELPER: build + sign + send
// ============================================================
function buildSign(string $bodyContent, string $action, array $cfg): string
{
    $ENDPOINT = $cfg['endpoint'];
    $WSU_NS   = $cfg['wsu'];
    $WSSE_NS  = $cfg['wsse'];
    $SOAP_NS  = $cfg['soap'];
    $WSA_NS   = $cfg['wsa'];
    $X509VT   = $cfg['x509vt'];
    $B64ET    = $cfg['b64et'];
    $DSIG_NS  = $cfg['dsig'];

    $bstId   = 'uuid-' . bin2hex(random_bytes(8)) . '-1';
    $msgId   = 'urn:uuid:' . sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0,0xffff), random_int(0,0xffff), random_int(0,0xffff),
            random_int(0,0x0fff)|0x4000, random_int(0,0x3fff)|0x8000,
            random_int(0,0xffff), random_int(0,0xffff), random_int(0,0xffff));
    $created = gmdate('Y-m-d\TH:i:s\Z');
    $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 300);

    $rawPem = file_get_contents($cfg['cert']);
    openssl_x509_export(openssl_x509_read($rawPem), $cleanPem);
    $certDer = base64_decode(preg_replace('/-----[^-]+-----|[\r\n\s]/', '', $cleanPem));
    $certB64 = base64_encode($certDer);

    $raw = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<s:Envelope'
        .' xmlns:s="'.$SOAP_NS.'"'
        .' xmlns:a="'.$WSA_NS.'"'
        .' xmlns:o="'.$WSSE_NS.'"'
        .' xmlns:u="'.$WSU_NS.'">'
        .'<s:Header>'
        .'<a:Action s:mustUnderstand="1">http://tempuri.org/IDegsNSS/'.$action.'</a:Action>'
        .'<a:MessageID>'.$msgId.'</a:MessageID>'
        .'<a:To s:mustUnderstand="1">'.$ENDPOINT.'</a:To>'
        .'<o:Security s:mustUnderstand="1">'
        .'<u:Timestamp u:Id="_ts">'
        .'<u:Created>'.$created.'</u:Created>'
        .'<u:Expires>'.$expires.'</u:Expires>'
        .'</u:Timestamp>'
        .'<o:BinarySecurityToken'
        .' u:Id="'.$bstId.'"'
        .' ValueType="'.$X509VT.'"'
        .' EncodingType="'.$B64ET.'">'
        .$certB64
        .'</o:BinarySecurityToken>'
        .'</o:Security>'
        .'</s:Header>'
        .'<s:Body u:Id="_body">'.$bodyContent.'</s:Body>'
        .'</s:Envelope>';

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->loadXML($raw);

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('u', $WSU_NS);
    $xp->registerNamespace('o', $WSSE_NS);

    foreach ($xp->query('//*[@u:Id]') as $node) {
        $node->setIdAttributeNS($WSU_NS, 'Id', true);
    }

    $dsig = new XMLSecurityDSig('');
    $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

    $refOpts = ['id_name'=>'Id','prefix'=>'u','prefix_ns'=>$WSU_NS,'overwrite'=>false];
    $dsig->addReference($dom, XMLSecurityDSig::SHA256, [XMLSecurityDSig::EXC_C14N],
        array_merge($refOpts, ['uri'=>'#_ts']));
    $dsig->addReference($dom, XMLSecurityDSig::SHA256, [XMLSecurityDSig::EXC_C14N],
        array_merge($refOpts, ['uri'=>'#_body']));

    $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type'=>'private']);
    $objKey->loadKey($cfg['key'], true);
    $dsig->sign($objKey);

    $secNode = $xp->query('//o:Security')->item(0);
    $dsig->appendSignature($secNode);

    $sigNode = $secNode->getElementsByTagNameNS($DSIG_NS, 'Signature')->item(0);
    $keyInfo = $dom->createElementNS($DSIG_NS, 'ds:KeyInfo');
    $strEl   = $dom->createElementNS($WSSE_NS, 'o:SecurityTokenReference');
    $refEl   = $dom->createElementNS($WSSE_NS, 'o:Reference');
    $refEl->setAttribute('URI', '#'.$bstId);
    $refEl->setAttribute('ValueType', $X509VT);
    $strEl->appendChild($refEl);
    $keyInfo->appendChild($strEl);
    $sigNode->appendChild($keyInfo);

    return $dom->saveXML();
}

function sendSoap(string $xml, string $action, array $cfg): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $cfg['endpoint'],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/soap+xml; charset=utf-8; action="http://tempuri.org/IDegsNSS/'.$action.'"',
        ],
        CURLOPT_SSLCERT        => $cfg['cert'],
        CURLOPT_SSLKEY         => $cfg['key'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO         => $cfg['ca'],
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$resp, 'err' => $err];
}

$cfg = [
    'endpoint' => $ENDPOINT,
    'cert'     => $CERT_PATH,
    'key'      => $KEY_PATH,
    'ca'       => $CA_PATH,
    'wsu'      => $WSU_NS,
    'wsse'     => $WSSE_NS,
    'soap'     => $SOAP_NS,
    'wsa'      => $WSA_NS,
    'x509vt'   => $X509VT,
    'b64et'    => $B64ET,
    'dsig'     => $DSIG_NS,
];

// ============================================================
// 3. TEST IsAlive
// ============================================================
echo "\n\n=== TEST 1: IsAlive ===\n";
$xml = buildSign('<IsAlive xmlns="http://tempuri.org/"/>', 'IsAlive', $cfg);
$r   = sendSoap($xml, 'IsAlive', $cfg);
echo "HTTP: {$r['code']}\n";
echo "Response: {$r['body']}\n";

// ============================================================
// 4. TEST SendRequest IsDelay=true (dry-run)
//    Using detected ORG_CODE and a fake but valid-format XML
// ============================================================
echo "\n\n=== TEST 2: SendRequest dry-run (IsDelay=true) ===\n";
echo "Using OrganisationCode: $orgCode\n";

$now     = now_fmt();
$nowDate = date('d/m/Y');
$nowTime = date('H:i:s');

// Minimal valid L001 XML
// DebtorID: dummy 13-digit (use a real bank_id from your DB!)
$DUMMY_BANK_ID = '1234567891012'; // 13 digits — replace with real one from clients table

// CreditCode: {orgCode(5)}-{Ymd(8)}-{seq(5)}{cs(1)}
$bank   = $orgCode;
$date   = date('Ymd');
$seq    = '00001';
$base18 = $bank . $date . $seq;
$cs     = cbaChecksum($base18);
$creditCode = "{$bank}-{$date}-{$seq}{$cs}";
echo "CreditCode: $creditCode\n";

$l001 = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<L001 xmlns="urn:cba-am:lnreg3">'
    .'<ReportHeader>'
    .'<OrganisationCode>'.$orgCode.'</OrganisationCode>'
    .'<OrganisationBranchCode>00001</OrganisationBranchCode>'
    .'<OrganizationStatus>1</OrganizationStatus>'
    .'<SendDateTime>'
    .'<Date>'.$nowDate.'</Date>'
    .'<Time>'.$nowTime.'</Time>'
    .'</SendDateTime>'
    .'</ReportHeader>'
    .'<CreditCode>'.$creditCode.'</CreditCode>'
    .'<LoanData>'
    .'<DebtorID>'.$DUMMY_BANK_ID.'</DebtorID>'
    .'<IsPE>N</IsPE>'
    .'<AffectionWithCreditor>N</AffectionWithCreditor>'
    .'<ContractType>1</ContractType>'
    .'<ContractNumber>TEST-001</ContractNumber>'
    .'<ContractDate>'.date('d/m/Y').'</ContractDate>'
    .'<RepaymentDate>'.date('d/m/Y', strtotime('+1 year')).'</RepaymentDate>'
    .'<LoanType>4</LoanType>'
    .'<Currency>AMD</Currency>'
    .'<ContractAmount>100000.00</ContractAmount>'
    .'<ContractModifiedAmount>100000.00</ContractModifiedAmount>'
    .'<AnnualInterestRate>51.10</AnnualInterestRate>'
    .'<ActualInterestRate>64.92</ActualInterestRate>'
    .'<InterestRateType>2</InterestRateType>'
    .'<IsInterestSubsidy>N</IsInterestSubsidy>'
    .'<ProvisionOfCredit>N</ProvisionOfCredit>'
    .'<LoanUseField>10.01.1</LoanUseField>'
    .'<LoanUseCountry>ARM</LoanUseCountry>'
    .'<LoanUseRegion>01000000</LoanUseRegion>'
    .'</LoanData>'
    .'</L001>';

$bodyContent =
    '<tns:SendRequest xmlns:tns="http://tempuri.org/">'
    .'<tns:AppName>ACREDIT</tns:AppName>'
    .'<tns:DocType>L001</tns:DocType>'
    .'<tns:IsDelay>true</tns:IsDelay>'
    .'<tns:xml><![CDATA['.$l001.']]></tns:xml>'
    .'</tns:SendRequest>';

$xml2 = buildSign($bodyContent, 'SendRequest', $cfg);
file_put_contents('/tmp/send_request_test.xml', $xml2);
$r2 = sendSoap($xml2, 'SendRequest', $cfg);
echo "HTTP: {$r2['code']}\n";
echo "Response: {$r2['body']}\n";

// ============================================================
// helpers
// ============================================================
function now_fmt(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

function cbaChecksum(string $digits): int {
    $len = strlen($digits);
    $sum = 0;
    for ($i = 0; $i < $len; $i++) {
        $d = (int)$digits[$len - 1 - $i];
        if ($i % 2 === 0) {
            $doubled = $d * 2;
            $sum += intdiv($doubled, 10) + ($doubled % 10);
        } else {
            $sum += $d;
        }
    }
    return (10 - ($sum % 10)) % 10;
}
