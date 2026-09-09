<?php
declare(strict_types=1); require __DIR__.'/_assert.php'; $root=dirname(__DIR__,2); $s=architecture_source($root);
foreach ([['Zef\\Framework\\Container','Zef\\Framework\\Http\\'],['Zef\\Framework\\Router','Zef\\Framework\\Container\\']] as [$from,$bad]) { $p=strpos($s,'namespace '.$from); architecture_check($p!==false,"namespace exists: $from"); if($p===false){throw new RuntimeException("namespace missing: $from");} $n=strpos($s,'namespace ',$p+10); $slice=($n===false)?substr($s,$p):substr($s,$p,$n-$p); architecture_check(!str_contains($slice,$bad),"$from does not depend on $bad"); }
