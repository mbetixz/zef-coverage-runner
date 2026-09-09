<?php

declare(strict_types=1);

/**
 * ZEF Framework — coverage gate (fail-closed).
 *
 * Reads a PHPUnit Clover report (var/coverage/coverage.xml by default) and
 * fails the build when line coverage is below the line threshold (default 80%)
 * or, when branch data is present, branch coverage is below the branch
 * threshold (default 70%).
 *
 * Exit codes:
 *   0  PASS — measured coverage meets every threshold.
 *   1  FAIL — measured coverage is below a threshold (fail-closed).
 *   2  ERROR — report missing/unreadable/malformed, or invalid arguments.
 *
 * Usage:
 *   php tools/coverage-gate.php [--clover=<path>]
 *                               [--line-threshold=<pct>]
 *                               [--branch-threshold=<pct>]
 *
 * The repository root is derived from this file's location (__DIR__/..) so the
 * gate is path-topology safe (see tools/verify-project-paths.php contract).
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Coverage gate: unable to resolve project root.\n");
    exit(2);
}

$clover = $root . '/var/coverage/coverage.xml';
$lineThreshold = 80.0;
$branchThreshold = 70.0;

$args = $argv;
array_shift($args);
foreach ($args as $arg) {
    if (str_starts_with($arg, '--clover=')) {
        $clover = substr($arg, strlen('--clover='));
        continue;
    }
    if (str_starts_with($arg, '--line-threshold=')) {
        $lineThreshold = (float) substr($arg, strlen('--line-threshold='));
        continue;
    }
    if (str_starts_with($arg, '--branch-threshold=')) {
        $branchThreshold = (float) substr($arg, strlen('--branch-threshold='));
        continue;
    }
    fwrite(STDERR, "Coverage gate: unknown argument: {$arg}\n");
    exit(2);
}

if (!is_file($clover)) {
    fwrite(
        STDERR,
        "Coverage gate: clover report not found at {$clover}.\n"
        . "Run phpunit with --coverage-clover first (see .gitlab-ci.yml job `coverage`).\n"
    );
    exit(2);
}

$xml = @simplexml_load_file($clover);
if ($xml === false) {
    fwrite(STDERR, "Coverage gate: unable to parse clover report: {$clover}\n");
    exit(2);
}

$metrics = $xml->project->metrics ?? null;
if ($metrics === null) {
    fwrite(STDERR, "Coverage gate: clover report has no <project><metrics> node.\n");
    exit(2);
}

$statements = (int) $metrics['statements'];
$coveredStatements = (int) $metrics['coveredstatements'];
$conditionals = (int) $metrics['conditionals'];
$coveredConditionals = (int) $metrics['coveredconditionals'];

if ($statements <= 0) {
    fwrite(STDERR, "Coverage gate: clover report contains no executable statements.\n");
    exit(2);
}

$linePercent = ($coveredStatements / $statements) * 100.0;
$branchPercent = null;
if ($conditionals > 0) {
    $branchPercent = ($coveredConditionals / $conditionals) * 100.0;
}

printf(
    "Coverage: %.2f%% lines (%d/%d) | threshold %.2f%%",
    $linePercent,
    $coveredStatements,
    $statements,
    $lineThreshold,
);
if ($branchPercent !== null) {
    printf(" | %.2f%% branches (%d/%d) | threshold %.2f%%", $branchPercent, $coveredConditionals, $conditionals, $branchThreshold);
} else {
    echo ' | branches: n/a (no branch data in report)';
}
echo "\n";

$failures = [];
if ($linePercent < $lineThreshold) {
    $failures[] = sprintf(
        'Line coverage %.2f%% is below the required %.2f%%.',
        $linePercent,
        $lineThreshold,
    );
}
if ($branchPercent !== null && $branchPercent < $branchThreshold) {
    $failures[] = sprintf(
        'Branch coverage %.2f%% is below the required %.2f%%.',
        $branchPercent,
        $branchThreshold,
    );
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . "\n");
    }
    exit(1);
}

echo "Coverage gate: PASS\n";
exit(0);
