<?php
declare(strict_types=1);

/**
 * Documentation consistency verifier — canonical ROADMAP.md contract.
 *
 * The repository canonical roadmap is `ROADMAP.md` (uppercase). On 2026-09-08
 * the duplicate legacy `roadmap.md` (lowercase) was removed and the planning
 * docs were consolidated into a single canonical roadmap in RESET / READY FOR
 * NEW ROADMAP state (EMPTY BY DESIGN, section 4). The legacy 16-milestone
 * (A–R) status-table contract no longer applies to the canonical file.
 *
 * This verifier enforces the invariants that DO apply to the RESET roadmap:
 *   1. `ROADMAP.md` exists and declares the canonical RESET state.
 *   2. `PROJECT_HANDOVER.md` exists and carries current governance context.
 *   3. Hard structural markers are present (single source of truth).
 *
 * Exit 0 = PASS, exit 1 = FAIL (fail-closed).
 */

$root = dirname(__DIR__);
$roadmapPath = $root . '/ROADMAP.md';
$handoverPath = $root . '/PROJECT_HANDOVER.md';

$roadmap = file_get_contents($roadmapPath);
$handover = file_get_contents($handoverPath);

if ($roadmap === false || $handover === false) {
    fwrite(STDERR, "Documentation consistency: required files missing.\n");
    exit(1);
}

$failures = [];

// --- Canonical roadmap contract (RESET mode) ---
// The roadmap declares its own canonical status. `# Zef Framework — Canonical
// Roadmap` (with a real U+2014 em dash) is the H1 anchor committed on
// 2026-09-08; `RESET / READY FOR NEW ROADMAP` is the declared state; the
// document must assert it is the single source of truth.
$canonicalChecks = [
    'Canonical Roadmap H1 anchor missing (must start with "# Zef Framework")' =>
        '# Zef Framework',
    'Roadmap must declare its RESET / READY state' =>
        'RESET / READY FOR NEW ROADMAP',
    'Roadmap must assert single source of truth' =>
        'Source of truth',
];

foreach ($canonicalChecks as $label => $needle) {
    if (!str_contains($roadmap, $needle)) {
        $failures[] = $label . '.';
    }
}

// --- Handover governance context ---
// PROJECT_HANDOVER.md must carry the current documentation-governance marker so
// future milestone work updates roadmap + handover in the same delivery.
if (!str_contains($handover, 'Documentation status')) {
    $failures[] = 'PROJECT_HANDOVER.md must include current governance context (Documentation status).';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Documentation consistency: PASS (canonical ROADMAP.md, RESET mode)\n";
exit(0);
