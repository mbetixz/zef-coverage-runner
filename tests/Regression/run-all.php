<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$steps=[
 'tests/Architecture/run-all.php',
 'tests/Regression/EventBusIntegrationTest.php',
 'tests/Regression/EventBusMonolithTest.php',
 'tests/Regression/CqrsIntegrationTest.php',
 'tests/Regression/CqrsMonolithTest.php','tests/Regression/MessageFoundationMonolithTest.php',
 'tests/Regression/JobSystemMonolithTest.php',
 'tests/Beta1/BehavioralEquivalenceTest.php',
 'tests/Regression/RuntimeGovernanceHardeningTest.php',
 'tests/Regression/SecurityHardeningTest.php',
 'tests/Regression/SecurityRuntimeE2Test.php',
 'tests/Regression/BehavioralNuancesTest.php',
 'tests/Regression/DeepResolutionGraphTest.php',
 'tests/Regression/RouterRadixTreeTest.php',
 'tests/Regression/ObservabilityTest.php',
 'tests/Regression/RoadRunnerRuntimeG1Test.php',
 'tests/Regression/RoadRunnerRuntimeG3Test.php',
 'tests/Regression/G4_2_TelemetryIntegrationBoundaryTest.php',
 'tests/Regression/G4_3_RuntimeFoundationTest.php',
];
foreach($steps as $step){
    if(!is_file($root.'/'.$step)){fwrite(STDERR,"MISSING: {$step}\n");exit(2);}
    passthru(PHP_BINARY.' '.escapeshellarg($root.'/'.$step),$rc);
    if($rc!==0){fwrite(STDERR,"FAIL: {$step}\n");exit($rc);}
}
require $root.'/zef_framework_v2.5.0-beta1.php';
$rc=(new \Zef\Test\CliRunner())->run(false);
if($rc!==0)exit($rc);
echo "Regression Qualification v2.5.0-beta1: PASS\n";

