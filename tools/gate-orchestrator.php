<?php
declare(strict_types=1);

/**
 * Unified mandatory-gate orchestrator (RM-14 / Fase F0).
 *
 * Menjalankan SEMUA release gate wajib dalam SATU perintah:
 *   php tools/gate-orchestrator.php            # tabel + ringkasan 1 baris; exit != 0 bila ada gate gagal
 *   php tools/gate-orchestrator.php --json     # output JSON (untuk CI / machine parsing)
 *   php tools/gate-orchestrator.php --skip-generate  # tanpa regenerasi artefak (verify only)
 *
 * Komposisi gate = komposisi KANONIK quality-gate.php + enterprise-preflight.php
 * (yang telah LOCKED hijau di C0-E2). Catatan RM-14:
 *  - Cs-fixer & deptrac adalah toolchain DEVELOPMENT (B1/B2) via .zef/ci-tools,
 *    BUKAN release gate kanonik — TIDAK disertakan di sini (konsisten dgn
 *    quality-gate.php & release-governance.php yang LOCKED).
 *  - Gate signature (Cosign) ditambahkan saat cosign tersedia (actual tagged
 *    release) — lihat tools/verify-release-signature.sh (RM-13, DEFERRED).
 *
 * FASE 1 — GENERATE (perbarui artefak kanonis dari state terkini):
 *   provenance        tools/generate-provenance.php      (provenance.intoto.json)
 *   release-manifest  tools/generate-release-manifest.php (RELEASE_MANIFEST_*)
 *   full-sha256       tools/generate-full-sha256.php     (SHA256SUMS.txt tree kerja)
 *   sbom              tools/generate-sbom.php            (SBOM CycloneDX)
 *
 * FASE 2 — VERIFY (release gate wajib; urutan = dependensi):
 *   project-paths     tools/verify-project-paths.php     (topologi path kanonik)
 *   architecture      tools/architecture-policy.php      (executable architecture policy)
 *   composer-contract composer.json/lock PHP >=8.4 + installability dry-run
 *   phpstan           vendor/bin/phpstan analyse         (level max, no NEW DEBT)
 *   regression        tests/Regression/run-all.php       (74/74)
 *   architecture-fit  tests/Architecture/run-all.php     (31/31, public-surface)
 *   phpunit           vendor/bin/phpunit                 (unit + qualification + integration)
 *   supply-chain      tools/verify-supply-chain.php      (provenance, SBOM, manifest.env)
 *   release-integrity tools/verify-release-integrity.php (SHA256SUMS-i, BD-04 root===dist)
 *   documentation     tools/documentation-consistency.php (doc↔kode konsisten)
 *   governance        tools/release-governance.php       (7-tool release orchestration)
 *   full-sha256       tools/verify-full-sha256.php       (verify SHA256SUMS.txt — PALING AKHIR)
 *   baseline-drift    tools/verify-baseline-drift.php    (PHPStan baseline drift; no new debt)
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Gate orchestrator: unable to resolve project root.\n");
    exit(2);
}

$json = in_array('--json', $argv, true);
$allowMissingPhpstan = in_array('--allow-missing-phpstan', $argv, true);
$skipGenerate = in_array('--skip-generate', $argv, true);
$strictPhpstan = !$allowMissingPhpstan;

/** @var list<array{name:string,cmd:string,label:string}> $generateSteps */
$generateSteps = [
    'provenance'        => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tools/generate-provenance.php'), 'label' => 'Generate provenance intoto'],
    'release-manifest'  => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tools/generate-release-manifest.php'), 'label' => 'Generate release manifest'],
    'full-sha256'       => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tools/generate-full-sha256.php'), 'label' => 'Generate full SHA-256 manifest'],
    'sbom'              => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tools/generate-sbom.php'), 'label' => 'Generate SBOM CycloneDX'],
];

/** @var list<array{name:string,cmd:string,label:string}> $gates */
$gates = [
    'project-paths'     => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tools/verify-project-paths.php'), 'label' => 'Project path topology'],
    'architecture'      => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tools/architecture-policy.php'), 'label' => 'Executable architecture policy'],
    'composer-contract' => ['cmd' => '', 'label' => 'Composer PHP >=8.4 + installability dry-run'],
    'phpstan'           => ['cmd' => '', 'label' => 'PHPStan level max (no NEW DEBT)'],
    'regression'        => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tests/Regression/run-all.php'), 'label' => 'Regression suite (74/74)'],
    'architecture-fit'  => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tests/Architecture/run-all.php'), 'label' => 'Architecture fitness (31/31, public-surface)'],
    'phpunit'           => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/vendor/bin/phpunit'), 'label' => 'PHPUnit (unit + qualification + integration)'],
    'supply-chain'      => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tools/verify-supply-chain.php'), 'label' => 'Supply-chain (provenance, SBOM, manifest.env, grype)'],
    'release-integrity' => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tools/verify-release-integrity.php'), 'label' => 'Release integrity (SHA256SUMS-i, BD-04 root===dist)'],
    'documentation'     => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tools/documentation-consistency.php'), 'label' => 'Documentation consistency'],
    'governance'        => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tools/release-governance.php'), 'label' => 'Release governance (7-tool orchestration)'],
    'full-sha256'       => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tools/verify-full-sha256.php'), 'label' => 'Full SHA-256 verification (paling akhir)'],
    'baseline-drift'    => ['cmd' => PHP_BINARY . ' ' . escapeshellarg($root . '/tools/verify-baseline-drift.php'), 'label' => 'PHPStan baseline drift (no new debt)'],
];

// Composer contract check (PHP >=8.4 + installability) — sama dgn quality-gate.php.
$gates['composer-contract']['cmd'] = PHP_BINARY . ' -r ' . escapeshellarg(
    '$root=' . var_export($root, true) . ';' .
    '$composer=json_decode((string)file_get_contents($root."/composer.json"),true,512,JSON_THROW_ON_ERROR);' .
    '$lock=json_decode((string)file_get_contents($root."/composer.lock"),true,512,JSON_THROW_ON_ERROR);' .
    '$ok=true;' .
    'if(($composer["require"]["php"]??null)!==">=8.4"){fwrite(STDERR,"composer.json PHP contract is not >=8.4\n");$ok=false;}' .
    'if(($lock["platform"]["php"]??null)!==">=8.4"){fwrite(STDERR,"composer.lock PHP platform is not >=8.4\n");$ok=false;}' .
    'exit($ok?0:1);'
) . ' && composer install --dry-run --no-dev --no-interaction --no-scripts --no-progress --working-dir=' . escapeshellarg($root);

// PHPStan: wajib bila strict (default).
$phpstanBin = $root . '/vendor/bin/phpstan';
if (is_file($phpstanBin)) {
    $gates['phpstan']['cmd'] = PHP_BINARY . ' -d memory_limit=-1 ' . escapeshellarg($phpstanBin)
        . ' analyse --configuration=' . escapeshellarg($root . '/phpstan.neon.dist') . ' --no-progress';
} elseif ($strictPhpstan) {
    $gates['phpstan']['cmd'] = '(exit 3)';
} else {
    $gates['phpstan']['cmd'] = '(exit 0)';
}

/**
 * @param list<array{name:string,cmd:string,label:string}> $steps
 * @param array<string,array{exit_code:int,status:string,tail:string}> $results
 * @param list<string> $failures
 */
function runSteps(array $steps, array &$results, array &$failures): void
{
    foreach ($steps as $step) {
        $output = [];
        $rc = -1;
        exec($step['cmd'] . ' 2>&1', $output, $rc);
        $ok = ($rc === 0);
        $results[$step['name']] = [
            'exit_code' => $rc,
            'status'    => $ok ? 'PASS' : 'FAIL',
            'tail'      => implode("\n", array_slice($output, -6)),
        ];
        if (!$ok) {
            $failures[] = $step['name'];
        }
    }
}

$allNames = array_merge(array_keys($generateSteps), array_keys($gates));
$maxNameLen = 0;
foreach ($allNames as $name) {
    $maxNameLen = max($maxNameLen, strlen((string) $name));
}
$pad = static function (string $name) use ($maxNameLen): string {
    return str_pad('== ' . $name . ' ==', $maxNameLen + 10);
};

/** @var array<string,array{exit_code:int,status:string,tail:string}> $results */
$results = [];
$failures = [];

if (!$skipGenerate) {
    echo "--- FASE 1: GENERATE ARTEFAK KANONIS ---\n";
    foreach ($generateSteps as $name => $step) {
        echo $pad($name) . "  {$step['label']}\n";
    }
    $genNames = array_map(static fn (string $n): array => ['name' => $n] + $generateSteps[$n], array_keys($generateSteps));
    runSteps($genNames, $results, $failures);
    foreach ($generateSteps as $name => $step) {
        echo $pad($name) . ' ' . $results[$name]['status'] . ' (exit ' . $results[$name]['exit_code'] . ")\n";
        if ($results[$name]['status'] !== 'PASS') {
            echo $results[$name]['tail'] . "\n";
        }
    }
    echo "\n";
} else {
    echo "--- FASE 1: SKIP GENERATE (--skip-generate) ---\n\n";
}

echo "--- FASE 2: VERIFY RELEASE GATE WAJIB ---\n";
foreach ($gates as $name => $gate) {
    echo $pad($name) . "  {$gate['label']}\n";
}
$gateNames = array_map(static fn (string $n): array => ['name' => $n] + $gates[$n], array_keys($gates));
runSteps($gateNames, $results, $failures);
foreach ($gates as $name => $gate) {
    echo $pad($name) . ' ' . $results[$name]['status'] . ' (exit ' . $results[$name]['exit_code'] . ")\n";
    if ($results[$name]['status'] !== 'PASS') {
        echo $results[$name]['tail'] . "\n";
    }
}

$allPass = ($failures === []);
$total = count($results);
$passed = $total - count($failures);
$summary = 'Gate orchestrator: ' . ($allPass ? 'PASS' : 'FAIL')
    . ' (' . $passed . '/' . $total . ' steps passed'
    . ($failures !== [] ? '; failed: ' . implode(', ', $failures) : '')
    . ')';

echo "\n=== SUMMARY ===\n";
foreach ($results as $name => $r) {
    echo str_pad((string) $name, $maxNameLen + 2) . $r['status'] . ' (exit ' . $r['exit_code'] . ")\n";
}
echo "\n{$summary}\n";

if ($json) {
    echo "\n=== JSON ===\n";
    echo json_encode([
        'tool' => 'zef-gate-orchestrator',
        'version' => '1.1.0',
        'phase' => 'F0/RM-14',
        'summary' => $summary,
        'all_pass' => $allPass,
        'generate_steps' => array_keys($generateSteps),
        'gates' => array_keys($gates),
        'results' => $results,
        'failures' => $failures,
        'timestamp' => gmdate('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

exit($allPass ? 0 : 1);
