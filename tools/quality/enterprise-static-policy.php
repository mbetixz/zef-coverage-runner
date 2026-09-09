<?php

declare(strict_types=1);

require_once __DIR__.'/StaticPolicyScanner.php';

$root = dirname(__DIR__, 2);
$result = scanEnterpriseStaticPolicy($root);
foreach ($result['advisories'] as $advisory) echo "ADVISORY: $advisory\n";
if ($result['hardViolations'] !== []) {
    foreach ($result['hardViolations'] as $violation) fwrite(STDERR, "FAIL: $violation\n");
    exit(1);
}
echo 'Enterprise static policy: PASS';
if ($result['advisories'] !== []) echo ' ('.count($result['advisories']).' advisory boundary item(s))';
echo "\n";
