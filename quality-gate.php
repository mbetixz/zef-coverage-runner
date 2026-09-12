<?php
declare(strict_types=1);

$root = __DIR__;
$failures = [];
$phpVersion = PHP_VERSION_ID;
$strict = !in_array('--allow-missing-phpstan', $argv, true);
if (version_compare(PHP_VERSION, '8.4.0', '<')) $failures[] = 'PHP >= 8.4 is required.';

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo) continue;
    if ($file->isFile() && $file->getExtension() === 'php') $files[] = $file->getPathname();
}
$files[] = $root.'/index.php';
/** @var list<string> $files */
foreach ($files as $file) {
    $output = [];
    exec(PHP_BINARY.' -l '.escapeshellarg($file), $output, $rc);
    if ($rc !== 0) $failures[] = 'Syntax failure: '.$file;
}

$required = [
    'composer.json','composer.lock','phpstan.neon.dist','build/build-monolith.php','tests/Regression/run-all.php','tools/architecture-policy.php',
    'tools/verify-project-paths.php','tools/release-governance.php',
        'tools/documentation-consistency.php','tools/verify-baseline-drift.php','phpstan-baseline-snapshot.json',
    'src/Framework/Message/Messages.php','src/Framework/Job/Jobs.php','src/Framework/Cache/Cache.php','src/Module/Health.php','tools/rr-healthcheck.php','tests/Architecture/public-surface-baseline-beta1-g3.json','tests/Architecture/public-surface-delta-v2.5.0-beta1-g3.json','tools/rr-healthcheck.php',
    'docs/JOBS_M.md','docs/CACHE_L2.md','docs/ROADRUNNER_G3.md',
    'docs/architecture/ADR-012-async-job-foundation.md','docs/architecture/ADR-013-cache-foundation.md','docs/architecture/ADR-010-h3-message-bus-foundation.md','docs/architecture/ADR-020-roadrunner-health-recovery-g3.md','docs/architecture/ADR-020-roadrunner-health-recovery-g3.md',
    'docs/architecture/ADR-014-supply-chain-security.md','docs/architecture/ADR-015-continuous-quality-governance.md','docs/architecture/ADR-021-roadrunner-worker-failure-recovery.md',
    'tests/Architecture/JobSystemGovernanceTest.php','tests/Architecture/CacheSystemGovernanceTest.php',
    'tests/Architecture/PublicSurfaceMGovernanceTest.php','tests/Architecture/PublicSurfaceL2GovernanceTest.php',
    'tests/Architecture/H3MessageFoundationGovernanceTest.php','tests/Architecture/PublicSurfaceH3GovernanceTest.php','tests/Architecture/G3RuntimeGovernanceTest.php','tests/Architecture/PublicSurfaceG3GovernanceTest.php','tests/Architecture/G3RuntimeGovernanceTest.php','tests/Architecture/PublicSurfaceG3GovernanceTest.php',
    'tests/Regression/JobSystemMonolithTest.php','tests/Regression/CacheSystemMonolithTest.php','tests/Regression/CacheApplicationIntegrationTest.php','tests/Regression/RoadRunnerRuntimeG3Test.php','benchmarks/g3_worker_failure_recovery.sh',
    'tests/Unit/JobSystemTest.php','tests/Unit/CacheSystemTest.php','tests/Unit/G4_4ToG4_6FoundationTest.php','tests/Architecture/PublicSurfaceG4_7GovernanceTest.php','tools/enterprise-preflight.php','src/Framework/Resource/Resource.php','docs/architecture/ADR-025-G4_4-configuration-secrets-governance.md','docs/architecture/ADR-026-G4_5-resource-protection-backpressure.md','docs/architecture/ADR-027-G4_6-distributed-messaging-foundation.md','docs/architecture/ADR-028-G4_7-enterprise-quality-hardening.md',
    'tests/Architecture/public-surface-baseline-beta1-l2.json','tests/Architecture/public-surface-delta-v2.5.0-beta1-l2.json','tests/Architecture/public-surface-baseline-beta1-g4_3.json','tests/Architecture/public-surface-baseline-beta1-g4_7.json','tests/Architecture/public-surface-delta-v2.5.0-beta1-g4.7.json',
    'benchmarks/m_jobs.php','benchmarks/l2_cache.php',
    'H3_MESSAGE_BUS_FOUNDATION_AUDIT.md','L2_CACHE_FOUNDATION_AUDIT.md','G3_ROADRUNNER_HEALTH_RECOVERY_AUDIT.md','G3_WORKER_FAILURE_RECOVERY_AUDIT.md','G3_WORKER_FAILURE_RECOVERY_FINAL.md','PROJECT_HANDOVER.md','ROADMAP.md','docs/ROADRUNNER_G3.md','docs/architecture/ADR-020-roadrunner-health-recovery-g3.md',
    'supply-chain/bom.cdx.json','supply-chain/provenance.intoto.json','supply-chain/SHA256SUMS-i.txt',
    'tools/generate-sbom.php','tools/generate-provenance.php','tools/generate-release-manifest.php','tools/generate-full-sha256.php','tools/verify-full-sha256.php','tools/verify-supply-chain.php','tools/verify-release-integrity.php','tools/sign-release.sh','tools/verify-release-signature.sh'
];
foreach ($required as $file) if (!is_file($root.'/'.$file)) $failures[] = 'Missing release gate file: '.$file;

$pathOutput = [];
$pathRc = 0;
exec(PHP_BINARY.' '.escapeshellarg($root.'/tools/verify-project-paths.php'), $pathOutput, $pathRc);
if ($pathRc !== 0) $failures[] = 'Project path topology failed.';

$architectureOutput = [];
$architectureRc = 0;
exec(PHP_BINARY.' '.escapeshellarg($root.'/tools/architecture-policy.php'), $architectureOutput, $architectureRc);
if ($architectureRc !== 0) $failures[] = 'Executable architecture policy failed.';

/** @var array<string,mixed> $composer */
$composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
/** @var array<string,mixed> $lock */
$lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$composerRequire = is_array($composer['require'] ?? null) ? $composer['require'] : [];
$lockPlatform = is_array($lock['platform'] ?? null) ? $lock['platform'] : [];
if (($composerRequire['php'] ?? null) !== '>=8.4') $failures[] = 'composer.json PHP contract is not >=8.4.';
if (($lockPlatform['php'] ?? null) !== '>=8.4') $failures[] = 'composer.lock PHP platform is not >=8.4.';

// Validate dependency installability, not only composer.json/lock JSON structure.
// This is intentionally fail-closed: a structurally valid but uninstallable lock
// must block release. The CI runner is expected to provide Composer and required
// PHP extensions; no platform requirements are ignored here.
$composerBin = getenv('COMPOSER_BIN');
if ($composerBin === false || $composerBin === '') {
    $localComposer = $root.'/composer.phar';
    $composerBin = is_file($localComposer)
        ? PHP_BINARY.' '.escapeshellarg($localComposer)
        : 'composer';
}
$installOutput = [];
$installRc = 0;
exec('cd '.escapeshellarg($root).' && '.$composerBin.' install --dry-run --no-dev --no-interaction --no-scripts --no-progress', $installOutput, $installRc);
if ($installRc !== 0) {
    $failures[] = 'Composer dependency installability check failed.';
}


$provenanceOutput=[]; $provenanceRc=0;
exec(PHP_BINARY.' '.escapeshellarg($root.'/tools/generate-provenance.php'), $provenanceOutput, $provenanceRc);
if ($provenanceRc !== 0) $failures[] = 'Provenance generation failed.';
$manifestOutput=[]; $manifestRc=0;
exec(PHP_BINARY.' '.escapeshellarg($root.'/tools/generate-release-manifest.php'), $manifestOutput, $manifestRc);
if ($manifestRc !== 0) $failures[] = 'Release manifest generation failed.';
$supplyOutput=[]; $supplyRc=0;
exec(PHP_BINARY.' '.escapeshellarg($root.'/tools/verify-supply-chain.php'), $supplyOutput, $supplyRc);
if ($supplyRc !== 0) $failures[] = 'Supply-chain verification failed.';
$integrityOutput=[]; $integrityRc=0;
exec(PHP_BINARY.' '.escapeshellarg($root.'/tools/verify-release-integrity.php'), $integrityOutput, $integrityRc);
if ($integrityRc !== 0) $failures[] = 'Release integrity verification failed.';

$docConsistencyOutput=[]; $docConsistencyRc=0;
exec(PHP_BINARY.' '.escapeshellarg($root.'/tools/documentation-consistency.php'), $docConsistencyOutput, $docConsistencyRc);
if ($docConsistencyRc !== 0) $failures[] = 'Documentation consistency check failed.';

$releaseGovernanceOutput=[]; $releaseGovernanceRc=0;
exec(PHP_BINARY.' '.escapeshellarg($root.'/tools/release-governance.php'), $releaseGovernanceOutput, $releaseGovernanceRc);
if ($releaseGovernanceRc !== 0) $failures[] = 'Release governance orchestration failed.';
$fullHashOutput=[]; $fullHashRc=0; exec(PHP_BINARY.' '.escapeshellarg($root.'/tools/generate-full-sha256.php'), $fullHashOutput, $fullHashRc); if ($fullHashRc !== 0) $failures[]='Full SHA256 manifest generation failed.'; $fullVerifyOutput=[]; $fullVerifyRc=0; exec(PHP_BINARY.' '.escapeshellarg($root.'/tools/verify-full-sha256.php'), $fullVerifyOutput, $fullVerifyRc); if ($fullVerifyRc !== 0) $failures[]='Full SHA256 manifest verification failed.';

$baselineDriftOutput=[]; $baselineDriftRc=0;
exec(PHP_BINARY.' '.escapeshellarg($root.'/tools/verify-baseline-drift.php'), $baselineDriftOutput, $baselineDriftRc);
if ($baselineDriftRc !== 0) $failures[] = 'PHPStan baseline drift check failed.';

$phpstan = $root.'/vendor/bin/phpstan';
if (is_file($phpstan)) {
    passthru(PHP_BINARY.' '.escapeshellarg($phpstan).' analyse --configuration='.escapeshellarg($root.'/phpstan.neon.dist').' --no-progress', $rc);
    if ($rc !== 0) $failures[] = 'PHPStan level max failed.';
} else {
    $message = 'PHPStan is not installed in the supplied vendor bundle; install phpstan/phpstan before release approval.';
    if ($strict) $failures[] = $message;
    else fwrite(STDERR, 'WARNING: '.$message."\n");
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, 'FAIL: '.$failure."\n");
    exit(1);
}
echo "Quality gate: PASS\n";
