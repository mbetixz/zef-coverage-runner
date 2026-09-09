<?php
declare(strict_types=1);
$dir=__DIR__;
$files=[
 'ArchitecturePolicyTest.php','DependencyDirectionTest.php','EventBusGovernanceTest.php','CqrsGovernanceTest.php','VendorCouplingTest.php','GlobalStateBoundaryTest.php',
 'VersionIntegrityTest.php','VersionAuthorityTest.php','BuildArtifactConsistencyTest.php',
 'MonolithEquivalenceTest.php','PublicSurfaceTest.php','TestArtifactCompletenessTest.php',
 'ServiceDefinitionInvariantTest.php','MiddlewareDefinitionInvariantTest.php','ModuleSystemGovernanceTest.php',
 'JobSystemGovernanceTest.php','CacheSystemGovernanceTest.php','G3RuntimeGovernanceTest.php',
 
 'RouteDefinitionInvariantTest.php','ModuleDefinitionInvariantTest.php','SemanticPublicSurfaceTest.php','PublicSurfaceG4_7GovernanceTest.php',
 'G4_8W2TransportGovernanceTest.php','G4_8W3DeliveryGovernanceTest.php','G4_8W3PublicSurfaceGovernanceTest.php','G4_8W4CorrelationGovernanceTest.php','G4_8W4PublicSurfaceGovernanceTest.php','G4_8W5SecurityGovernanceTest.php','G4_8W5PublicSurfaceGovernanceTest.php','G4_8W6QualificationGovernanceTest.php','G4_8W6PublicSurfaceGovernanceTest.php',
];
foreach($files as $f){if(!is_file($dir.'/'.$f)){fwrite(STDERR,"MISSING: {$f}\n");exit(2);}}
$ok=0;
foreach($files as $f){passthru(PHP_BINARY.' '.escapeshellarg($dir.'/'.$f),$rc);if($rc!==0){fwrite(STDERR,"FAIL: {$f}\n");exit($rc);}++$ok;}
echo "Architecture Fitness Functions: {$ok}/{$ok} PASS\n";

