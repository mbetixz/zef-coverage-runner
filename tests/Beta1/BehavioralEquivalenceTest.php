<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$scenario=$root.'/tests/Beta1/behavior_scenario.php';

/** @return array<string,mixed> */
function runScenario(string $mode,string $script):array { $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($mode); exec($cmd,$out,$rc); if($rc!==0) throw new RuntimeException("Scenario {$mode} failed: ".implode("\n",$out)); $json=json_decode(implode("\n",$out),true,512,JSON_THROW_ON_ERROR); if(!is_array($json)) throw new RuntimeException("Scenario {$mode} produced invalid JSON"); /** @var array<string,mixed> $json */ return $json; }

/**
 * @param array<string,mixed> $data
 * @return array{cases:list<array<string,mixed>>}
 */
function normalize(array $data):array { $v=$data['version']; $v=is_string($v)?$v:''; $cases=$data['cases']; $cases=is_array($cases)?$cases:[]; foreach($cases as $i=>$case){ if(!is_array($case)){ continue; } $body=$case['body']??''; $case['body']=str_replace([$v,'2.5.0-alpha5.2','2.5.0-beta1'],'<VERSION>',is_string($body)?$body:''); $headers=$case['headers']??[]; if(is_array($headers)){ unset($headers['X-Request-ID'],$headers['X-Response-Time']); $case['headers']=$headers; } $cases[$i]=$case; } $result=['cases'=>array_values($cases)]; /** @var array{cases:list<array<string,mixed>>} $result */ return $result; }
$b=normalize(runScenario('baseline',$scenario));$m=normalize(runScenario('modular',$scenario));$g=normalize(runScenario('monolith',$scenario));
if($b!==$m) {echo "FAIL: modular runtime differs from baseline\n";var_export([$b,$m]);exit(1);} echo "PASS: modular runtime behavior equals baseline\n";
if($m!==$g) {echo "FAIL: generated monolith differs from modular runtime\n";var_export([$m,$g]);exit(1);} echo "PASS: generated monolith behavior equals modular runtime\n";
echo "BehavioralEquivalenceTest: PASS\n";
