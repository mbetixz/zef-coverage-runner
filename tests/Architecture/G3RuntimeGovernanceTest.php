<?php
declare(strict_types=1);
$root=dirname(__DIR__,2); require $root.'/vendor/autoload.php';
$required=['worker.php','.rr.yaml','src/Module/Health.php'];$fail=0;$pass=0;
foreach($required as $file){$ok=is_file($root.'/'.$file);if($ok){++$pass;echo "PASS: G3 artifact {$file}\n";}else{++$fail;echo "FAIL: G3 artifact {$file}\n";}}
$src=file_get_contents($root.'/worker.php');$ok=$src!==false&&str_contains($src,'RoadRunnerRuntime');if($ok){++$pass;echo "PASS: G3 worker uses RoadRunnerRuntime\n";}else{++$fail;echo "FAIL: G3 worker uses RoadRunnerRuntime\n";}
$rr=file_get_contents($root.'/.rr.yaml');$ok=$rr!==false&&str_contains($rr,'supervisor:');if($ok){++$pass;echo "PASS: G3 supervisor configuration present\n";}else{++$fail;echo "FAIL: G3 supervisor configuration present\n";}
echo "TOTAL PASS: {$pass}  FAIL: {$fail}\n";exit($fail===0?0:1);
