<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'project paths' => $root.'/tools/verify-project-paths.php',
    'documentation consistency' => $root.'/tools/documentation-consistency.php',
    'architecture fitness' => $root.'/tools/architecture-policy.php',
    'regression suite' => $root.'/tests/Regression/run-all.php',
];
foreach ($checks as $label => $file) {
    if (!is_file($file)) throw new RuntimeException("Missing preflight target: {$file}");
    passthru(PHP_BINARY.' '.escapeshellarg($file), $rc);
    if ($rc !== 0) throw new RuntimeException("Enterprise preflight failed: {$label}");
}
// B0 (RM-07): G4_4ToG4_6FoundationTest was converted from a plain script to a
// PHPUnit TestCase. It MUST run through the canonical PHPUnit bootstrap
// (phpunit.xml.dist -> vendor/autoload.php), never as a raw standalone script.
$phpunitTargets = [
    'foundation tests' => $root.'/tests/Unit/G4_4ToG4_6FoundationTest.php',
];
foreach ($phpunitTargets as $label => $file) {
    if (!is_file($file)) throw new RuntimeException("Missing preflight target: {$file}");
    passthru(PHP_BINARY.' '.escapeshellarg($root.'/vendor/bin/phpunit').' '.escapeshellarg($file), $rc);
    if ($rc !== 0) throw new RuntimeException("Enterprise preflight failed: {$label}");
}
$before = hash_file('sha256', $root.'/dist/zef_framework_v2.5.0-beta1-phase5.php');
if ($before === false) throw new RuntimeException('Cannot hash existing monolith.');
exec(PHP_BINARY.' '.escapeshellarg($root.'/build/build-monolith.php'), $out, $rc);
if ($rc !== 0) throw new RuntimeException('Monolith regeneration failed.');
$after = hash_file('sha256', $root.'/dist/zef_framework_v2.5.0-beta1-phase5.php');
if ($after === false) throw new RuntimeException('Cannot hash regenerated monolith.');
if ($before !== $after) throw new RuntimeException('Monolith build is not idempotent.');
if (!hash_equals(hash_file('sha256', $root.'/zef_framework_v2.5.0-beta1.php'), $after)) throw new RuntimeException('Root/dist monolith drift detected.');
echo "Enterprise preflight: PASS\n";
