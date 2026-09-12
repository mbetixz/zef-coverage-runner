<?php
declare(strict_types=1);

/**
 * R — Continuous Quality: PHPStan Baseline Drift Detector.
 *
 * Enforces Non-Negotiable Rule #7: "The PHPStan baseline may not hide new debt."
 *
 * Three invariants are checked:
 *
 *   1. UNBASELINED ERROR COUNT == 0
 *      Running PHPStan with the current baseline must yield zero errors.
 *      If any error appears, it is NEW debt that was introduced after the
 *      baseline was captured and is NOT covered by the baseline — meaning
 *      someone added defective code without fixing it or baselining it.
 *      This is a hard failure.
 *
 *   2. BASELINE COUNT <= SNAPSHOT FLOOR
 *      The current baseline's entry count and total error count must not
 *      exceed the snapshot floor stored in phpstan-baseline-snapshot.json.
 *      The floor is the maximum allowed debt. Each remediation batch must
 *      reduce debt, never increase it. If the baseline grew, new entries
 *      were added to hide debt rather than fixing it — a Rule #7 violation.
 *
 *   3. SNAPSHOT FILE EXISTS AND IS VALID
 *      The snapshot floor file must exist and be parseable. Without a floor,
 *      drift cannot be detected. This is fail-closed.
 *
 * Exit codes:
 *   0 — All invariants hold (no drift, no new debt).
 *   1 — Drift detected (new unbaselined errors OR baseline exceeded floor).
 *   2 — Configuration error (missing snapshot, missing PHPStan, etc.).
 *
 * CATATAN KONFIGURASI (RCA OOM PHPStan 2026-09-12):
 *   Analisis PHPStan dijalankan memakai OVERLAY `phpstan-ci.neon` yang
 *   meng-import `phpstan.neon.dist` + membatasi parallel.maximumNumberOfProcesses
 *   (tanpa section itu, default = jumlah core -> agregat RSS ~1,4 GB menembus
 *   cgroup runner GitLab ~1 GB -> SIGKILL exit 137; terukur ulang: base 9 proses
 *   / 1393 MB vs overlay 3 proses / 528 MB). Bila overlay tidak ada (mis. repo
 *   kanonik belum memuatnya), jatuh kembali ke `phpstan.neon.dist` supaya tool
 *   tetap deterministik & tidak fail-closed hanya karena file tak ada.
 *   Overlay WAJIB ada di subtree mirror (OFFLOAD_PATHS) agar paritas terjaga.
 */

$root = dirname(__DIR__);
$failures = [];

// ── Invariant 3: Snapshot floor must exist and be valid ──────────────────────

$snapshotPath = $root . '/phpstan-baseline-snapshot.json';
if (!is_file($snapshotPath)) {
    fwrite(STDERR, "FAIL: Baseline snapshot floor not found: {$snapshotPath}\n");
    fwrite(STDERR, "      Without a floor, baseline drift cannot be detected.\n");
    exit(2);
}

$snapshotRaw = file_get_contents($snapshotPath);
if ($snapshotRaw === false) {
    fwrite(STDERR, "FAIL: Cannot read baseline snapshot: {$snapshotPath}\n");
    exit(2);
}

$decoded = json_decode($snapshotRaw, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($decoded)) {
    fwrite(STDERR, "FAIL: Baseline snapshot is not a JSON object.\n");
    exit(2);
}
/** @var array<string,mixed> $snapshot */
$snapshot = $decoded;
$floorEntriesRaw = $snapshot['entries'] ?? null;
$floorErrorsRaw  = $snapshot['errors']  ?? null;
$floorEntries = is_int($floorEntriesRaw) ? $floorEntriesRaw : -1;
$floorErrors  = is_int($floorErrorsRaw)  ? $floorErrorsRaw  : -1;

if ($floorEntries < 0 || $floorErrors < 0) {
    fwrite(STDERR, "FAIL: Baseline snapshot is missing 'entries' or 'errors' field.\n");
    exit(2);
}

echo "Baseline snapshot floor: {$floorEntries} entries / {$floorErrors} errors\n";

// ── Parse current baseline to count entries and total errors ─────────────────

$baselinePath = $root . '/phpstan-baseline.neon';
if (!is_file($baselinePath)) {
    fwrite(STDERR, "FAIL: PHPStan baseline not found: {$baselinePath}\n");
    exit(2);
}

$baselineRaw = file_get_contents($baselinePath);
if ($baselineRaw === false) {
    fwrite(STDERR, "FAIL: Cannot read PHPStan baseline.\n");
    exit(2);
}

// Count entries (blocks with 'message:') and sum 'count:' values (default 1).
$currentEntries = 0;
$currentErrors  = 0;
$blocks = explode("\n\t\t-\n", $baselineRaw);
foreach ($blocks as $block) {
    if (!str_contains($block, 'message:')) {
        continue;
    }
    ++$currentEntries;
    if (preg_match('/count:\s*(\d+)/', $block, $m)) {
        $currentErrors += (int)$m[1];
    } else {
        ++$currentErrors;
    }
}

echo "Current baseline:        {$currentEntries} entries / {$currentErrors} errors\n";

// ── Invariant 2: Baseline must not exceed floor ──────────────────────────────

if ($currentEntries > $floorEntries) {
    $delta = $currentEntries - $floorEntries;
    $failures[] = "Baseline entry count increased by {$delta} ({$currentEntries} > floor {$floorEntries}). New debt was baselined instead of fixed — Rule #7 violation.";
}
if ($currentErrors > $floorErrors) {
    $delta = $currentErrors - $floorErrors;
    $failures[] = "Baseline total error count increased by {$delta} ({$currentErrors} > floor {$floorErrors}). New debt was baselined instead of fixed — Rule #7 violation.";
}

if ($currentEntries < $floorEntries) {
    $reduced = $floorEntries - $currentEntries;
    echo "Debt reduction: {$reduced} entries burned since floor (good)\n";
}

// ── Invariant 1: PHPStan with baseline must yield zero unbaselined errors ───

$phpstan = $root . '/vendor/bin/phpstan';
if (!is_file($phpstan)) {
    fwrite(STDERR, "FAIL: PHPStan binary not found at {$phpstan}\n");
    exit(2);
}

// Prefer overlay CI (batas worker paralel -> bebas OOM cgroup). Fallback ke
// konfigurasi kanonik bila overlay belum tersedia.
$ciOverlay = $root . '/phpstan-ci.neon';
$config = is_file($ciOverlay) ? $ciOverlay : $root . '/phpstan.neon.dist';
if (!is_file($config)) {
    fwrite(STDERR, "FAIL: PHPStan config not found: {$config}\n");
    exit(2);
}
echo "PHPStan config: " . basename($config) . "\n";

$output = [];
$rc = 0;
// RCA OOM (2026-09-12) — lapis kedua. Job `baseline-drift` sebelumnya memanggil
// PHPStan dengan `PHP_BINARY` polos sehingga memory_limit default image (128M)
// berlaku: PHPStan worker mati "reached configured PHP memory limit: 128M"
// -> job merah walau jumlah worker sudah dibatasi overlay. Hormati
// PHP_MEMORY_LIMIT dari environment bila ada (default tetap 1G).
$memLimit = getenv('PHP_MEMORY_LIMIT');
if ($memLimit === false || trim((string) $memLimit) === '') {
    $memLimit = '1024M';
}
exec(
    PHP_BINARY . ' -d memory_limit=' . escapeshellarg($memLimit)
    . ' ' . escapeshellarg($phpstan)
    . ' analyse --configuration=' . escapeshellarg($config)
    . ' --no-progress --error-format=raw 2>&1',
    $output,
    $rc
);

if ($rc !== 0) {
    $errorCount = count(array_filter($output, fn($l) => $l !== '' && !str_starts_with($l, 'Note:') && !str_starts_with($l, 'Path ')));
    $failures[] = "PHPStan reported {$errorCount} unbaselined error(s) — new debt is not covered by the baseline. Rule #7 violation.";
    foreach ($output as $line) {
        if ($line !== '' && !str_starts_with($line, 'Note:') && !str_starts_with($line, 'Path ')) {
            fwrite(STDERR, "  UNBASELINED: {$line}\n");
        }
    }
}

// ── Verdict ──────────────────────────────────────────────────────────────────

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Baseline drift: PASS (no new debt, baseline within floor)\n";
