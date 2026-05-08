<?php

$xmlFile = '/tmp/degs_v9.xml';

$dom = new DOMDocument();
$dom->preserveWhiteSpace = false;
$dom->loadXML(file_get_contents($xmlFile));

$xp = new DOMXPath($dom);

$xp->registerNamespace('s',  'http://www.w3.org/2003/05/soap-envelope');
$xp->registerNamespace('u',  'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd');
$xp->registerNamespace('o',  'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd');
$xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

/**
 * 1. Check required nodes
 */
$nodes = [
    'Timestamp' => '//u:Timestamp',
    'Body'      => '//s:Body',
    'BST'       => '//o:BinarySecurityToken',
    'Signature' => '//ds:Signature',
];

echo "=== NODE CHECK ===\n";

foreach ($nodes as $name => $q) {
    $n = $xp->query($q);
    echo $name . ": " . ($n->length ? "OK" : "MISSING") . PHP_EOL;
}

/**
 * 2. Extract SignedInfo
 */
$signedInfoNode = $xp->query('//ds:SignedInfo')->item(0);

if (!$signedInfoNode) {
    die("❌ SignedInfo missing\n");
}

$signedInfoC14N = $signedInfoNode->C14N(true, false);

echo "\n=== SIGNEDINFO C14N ===\n";
echo substr($signedInfoC14N, 0, 300) . "\n";

/**
 * 3. Check SignatureValue exists
 */
$sigValueNode = $xp->query('//ds:SignatureValue')->item(0);

echo "\n=== SIGNATURE VALUE ===\n";
echo $sigValueNode ? "OK\n" : "MISSING\n";

/**
 * 4. Thumbprint check
 */
$keyId = $xp->query('//ds:KeyIdentifier')->item(0);

echo "\n=== KEY IDENTIFIER ===\n";
if (!$keyId) {
    echo "MISSING\n";
} else {
    echo "ValueType: " . $keyId->getAttribute('ValueType') . PHP_EOL;
    echo "Value: " . trim($keyId->textContent) . PHP_EOL;
}

/**
 * 5. Digest values audit
 */
echo "\n=== DIGEST VALUES ===\n";

$refs = $xp->query('//ds:Reference');

foreach ($refs as $ref) {
    $uri = $ref->getAttribute('URI');
    $digest = $xp->query('.//ds:DigestValue', $ref)->item(0)?->textContent;

    echo "Reference: $uri\n";
    echo "Digest   : $digest\n\n";
}

/**
 * 6. Detect common DEGS/WCF mistakes
 */
echo "=== COMMON ISSUES CHECK ===\n";

// 1. missing BST
if ($xp->query('//o:BinarySecurityToken')->length == 0) {
    echo "❌ BST missing\n";
}

// 2. wrong KeyIdentifier type
$keyType = $xp->query('//ds:KeyIdentifier')->item(0)?->getAttribute('ValueType');
if ($keyType && !str_contains($keyType, 'Thumbprint')) {
    echo "❌ KeyIdentifier ValueType suspicious: $keyType\n";
}

// 3. signature order check
$security = $xp->query('//o:Security')->item(0);
if ($security) {
    $children = [];
    foreach ($security->childNodes as $c) {
        if ($c instanceof DOMElement) {
            $children[] = $c->nodeName;
        }
    }

    echo "\nSecurity order:\n";
    print_r($children);

    if (strpos(implode(',', $children), 'ds:Signature') === false) {
        echo "❌ Signature missing in Security\n";
    }
}

/**
 * 7. Final hint engine
 */
echo "\n=== DIAGNOSIS ===\n";

if ($xp->query('//ds:Signature')->length && $xp->query('//ds:SignatureValue')->length) {
    echo "Signature structure OK\n";
} else {
    echo "❌ Signature structure broken\n";
}

echo "\nDone\n";
