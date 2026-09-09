<?php
declare(strict_types=1);
require __DIR__.'/_assert.php';
$root=dirname(__DIR__,2);
architecture_check(is_file($root.'/tests/Architecture/ArchitecturePolicyTest.php'),'required test artifact exists: tests/Architecture/ArchitecturePolicyTest.php');
architecture_check(is_file($root.'/tests/Architecture/PublicSurfaceGovernanceTest.php'),'required test artifact exists: tests/Architecture/PublicSurfaceGovernanceTest.php');
architecture_check(is_file($root.'/tests/Architecture/CqrsGovernanceTest.php'),'required test artifact exists: tests/Architecture/CqrsGovernanceTest.php');
architecture_check(is_file($root.'/tools/architecture-policy.php'),'required architecture policy exists: tools/architecture-policy.php');
architecture_check(is_file($root.'/tests/Architecture/CacheSystemGovernanceTest.php'),'required test artifact exists: tests/Architecture/CacheSystemGovernanceTest.php');
architecture_check(is_file($root.'/tests/Architecture/PublicSurfaceL2GovernanceTest.php'),'required test artifact exists: tests/Architecture/PublicSurfaceL2GovernanceTest.php');
architecture_check(is_file($root.'/tests/Regression/CacheSystemMonolithTest.php'),'required test artifact exists: tests/Regression/CacheSystemMonolithTest.php');
architecture_check(is_file($root.'/tests/Unit/CacheSystemTest.php'),'required test artifact exists: tests/Unit/CacheSystemTest.php');
architecture_check(is_file($root.'/src/Framework/Cache/Cache.php'),'required cache foundation exists: src/Framework/Cache/Cache.php');

$required=[
 'tests/Architecture/DependencyDirectionTest.php',
 'tests/Architecture/VendorCouplingTest.php',
 'tests/Architecture/GlobalStateBoundaryTest.php',
 'tests/Architecture/VersionIntegrityTest.php',
 'tests/Architecture/BuildArtifactConsistencyTest.php',
 'tests/Architecture/MonolithEquivalenceTest.php',
 'tests/Architecture/PublicSurfaceTest.php',
 'tests/Architecture/ServiceDefinitionInvariantTest.php',
 'tests/Architecture/MiddlewareDefinitionInvariantTest.php',
 'tests/Architecture/RouteDefinitionInvariantTest.php',
 'tests/Architecture/ModuleDefinitionInvariantTest.php',
 'tests/Architecture/SemanticPublicSurfaceTest.php',
 'tests/Regression/BehavioralNuancesTest.php',
 'tests/Regression/SecurityRuntimeE2Test.php',
 'tests/Regression/VersionConsistencyTest.php',
 'tests/Regression/ReleaseQualification.php',
 'tests/Regression/run-all.php',
 'tests/Regression/DeepResolutionGraphTest.php',
 'tests/Beta1/BehavioralEquivalenceTest.php',
 'tests/Beta1/behavior_scenario.php',
 'build/build-monolith.php',
 'src/autoload.php',
 'src/kernel.php',
 'tests/_history/public-surface-baseline-alpha4.php',
 'tests/Architecture/public-surface-baseline-beta1.json',
 'tests/Architecture/public-surface-delta-v2.5.0-beta1.json',
 'tests/Architecture/public-surface-baseline-beta1-observability.json',
 'tests/Architecture/public-surface-baseline-beta1-container-compiler-e2.json',
 'tests/Architecture/public-surface-delta-v2.5.0-beta1-e2.json',
 'tests/Architecture/public-surface-baseline-beta1-h1.json',
 'tests/Architecture/public-surface-delta-v2.5.0-beta1-h1.json',
 'tests/Unit/CqrsTest.php',
 'tests/Regression/CqrsIntegrationTest.php',
 'tests/Regression/CqrsMonolithTest.php',
 'src/Framework/CQRS/Cqrs.php',
];
foreach($required as $f) architecture_check(is_file($root.'/'.$f),'required test artifact exists: '.$f);
