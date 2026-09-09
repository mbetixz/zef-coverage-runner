<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$files = [
    'composer.json',
    'composer.lock',
    'zef_framework_v2.5.0-beta1.php',
    'dist/zef_framework_v2.5.0-beta1-phase5.php',
    'supply-chain/bom.cdx.json',
    'docs/PRODUCTION_WEB_RESILIENCE_QUALIFICATION.md',
    'benchmarks/g3_production_web_resilience.sh',
    'benchmarks/g3_long_running_soak.sh',
    'benchmarks/g3_request_state_isolation.php',
    'docs/SOAK_CAPACITY_EVIDENCE.md',
    'docs/SOAK_CAPACITY_QUALIFICATION_README.md',
    'benchmarks/g3_capacity_characterization.sh',
    'benchmarks/g3_soak_60s.sh',
    'benchmarks/g3_long_duration_soak.sh',
    'docs/LONG_DURATION_SOAK_QUALIFICATION.md',
    'docs/LONG_DURATION_SOAK_REMEDIATION.md',
    'docs/ROADRUNNER_G3.md',
    'benchmarks/g3_worker_hardening.sh',
    'tests/Regression/RoadRunnerRuntimeG2Test.php',
    'tests/Regression/RoadRunnerRuntimeG3Test.php',
    'tests/Architecture/G3RuntimeGovernanceTest.php',
    'tests/Architecture/PublicSurfaceG3GovernanceTest.php',
    'docs/ROADRUNNER_G3.md',
    'benchmarks/g3_worker_failure_recovery.sh',
    'benchmarks/g3_worker_hardening.sh',
    'tests/Regression/G4_1_ObservabilityOperationalResilienceTest.php',
    'tests/Regression/G4_2_TelemetryIntegrationBoundaryTest.php',
    'tests/Integration/G4_2_OTLPBoundaryIntegrationTest.php',
    'tests/Regression/G4_3_RuntimeFoundationTest.php',
    'docs/architecture/ADR-020-roadrunner-health-recovery-g3.md',
    'src/Framework/Resource/Resource.php',
    'tests/Unit/G4_4ToG4_6FoundationTest.php',
    'tests/Architecture/PublicSurfaceG4_7GovernanceTest.php',
    'tests/Architecture/public-surface-baseline-beta1-g4_3.json',
    'tests/Architecture/public-surface-baseline-beta1-g4_7.json',
    'tests/Architecture/public-surface-delta-v2.5.0-beta1-g4.7.json',
    'tools/enterprise-preflight.php',
    'tools/generate-full-sha256.php',
    'tools/verify-full-sha256.php',
    'ROADMAP.md',
    'PROJECT_HANDOVER.md',
    'src/Framework/Security/Distributed.php',
    'tests/Unit/G4_8DistributedSecurityTest.php',
    'tests/Architecture/G4_8W5SecurityGovernanceTest.php',
    'tests/Architecture/G4_8W5PublicSurfaceGovernanceTest.php',
    'tests/Architecture/public-surface-delta-v2.5.0-beta1-g4.8-w5.json',
    'tools/g4_8_w5_contract_smoke.php',
    'tools/g4_8_w5_failure_matrix.php',
    'tools/g4_8_w5_performance.php',
    'src/Framework/Observability/Correlation.php',
    'tests/Unit/G4_8CorrelationContextTest.php',
    'tests/Architecture/G4_8W4CorrelationGovernanceTest.php',
    'tests/Architecture/G4_8W4PublicSurfaceGovernanceTest.php',
    'tests/Architecture/public-surface-delta-v2.5.0-beta1-g4.8-w4.json',
    'tools/g4_8_w4_contract_smoke.php',
    'tools/g4_8_w4_failure_matrix.php',
    'tools/g4_8_w4_performance.php',
    'src/Framework/Qualification/Governance.php',
    'tests/Unit/G4_8QualificationGovernanceTest.php',
    'tests/Architecture/G4_8W6QualificationGovernanceTest.php',
    'tests/Architecture/G4_8W6PublicSurfaceGovernanceTest.php',
    'tests/Architecture/public-surface-delta-v2.5.0-beta1-g4.8-w6.json',
    'tests/Architecture/run-all.php',
    'tools/g4_8_w6_contract_smoke.php',
    'docs/architecture/ADR-034-QUALIFICATION-GOVERNANCE.md',
];
$subjects = [];
foreach ($files as $file) {
    $path = $root.'/'.$file;
    if (!is_file($path)) continue;
    $subjects[] = [
        'name' => $file,
        'digest' => ['sha256' => hash_file('sha256', $path)],
    ];
}
$lockHash = hash_file('sha256', $root.'/composer.lock');
$invocation = 'urn:zef:build:'.substr(hash('sha256', 'zef-provenance:'.$lockHash), 0, 32);
$statement = [
    '_type' => 'https://in-toto.io/Statement/v1',
    'subject' => $subjects,
    'predicateType' => 'https://slsa.dev/provenance/v1',
    'predicate' => [
        'buildDefinition' => [
            'buildType' => 'https://zef.dev/build/v1',
            'externalParameters' => [
                'project' => 'zef/framework',
                'version' => '2.5.0-beta1',
            ],
            'resolvedDependencies' => [[
                'uri' => 'file:composer.lock',
                'digest' => ['sha256' => $lockHash],
            ]],
        ],
        'runDetails' => [
            'builder' => ['id' => 'https://zef.dev/ci/build'],
            'metadata' => [
                'invocationId' => $invocation,
                'startedOn' => '1970-01-01T00:00:00Z',
                'finishedOn' => '1970-01-01T00:00:00Z',
            ],
        ],
    ],
];
file_put_contents($root.'/supply-chain/provenance.intoto.json', json_encode($statement, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "Provenance generated\n";
