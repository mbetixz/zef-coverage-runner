<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Release governance: unable to resolve project root.\n");
    exit(2);
}

/** @return int */
function runTool(string $root, string $relative): int
{
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: Missing governance tool: {$relative}\n");
        return 2;
    }
    passthru(PHP_BINARY . ' ' . escapeshellarg($path), $rc);
    return $rc;
}

$steps = [
    'project-paths' => 'tools/verify-project-paths.php',
    'documentation' => 'tools/documentation-consistency.php',
    'baseline-drift' => 'tools/verify-baseline-drift.php',
    'provenance' => 'tools/generate-provenance.php',
    'release-manifest' => 'tools/generate-release-manifest.php',
    'supply-chain' => 'tools/verify-supply-chain.php',
    'release-integrity' => 'tools/verify-release-integrity.php',
];

$failures = [];
foreach ($steps as $name => $relative) {
    echo "== {$name} ==\n";
    $rc = runTool($root, $relative);
    if ($rc !== 0) {
        $failures[] = $name;
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'Release governance: FAIL (' . implode(', ', $failures) . ")\n");
    exit(1);
}

echo "Release governance: PASS\n";
exit(0);
