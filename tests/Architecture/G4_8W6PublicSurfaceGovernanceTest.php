<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
/** @var array{added_classes?: array<mixed>, added_methods?: array<mixed>, removed_classes?: array<mixed>, removed_methods?: array<mixed>} $delta */
$delta=json_decode((string)file_get_contents($root.'/tests/Architecture/public-surface-delta-v2.5.0-beta1-g4.8-w6.json'),true,512,JSON_THROW_ON_ERROR);
$checks=[count($delta['added_classes']??[])===5,count($delta['added_methods']??[])===5,count($delta['removed_classes']??[])===0,count($delta['removed_methods']??[])===0];
foreach($checks as $i=>$ok){echo($ok?'PASS':'FAIL')." public-surface-control-".($i+1)."\n";if(!$ok)exit(1);}echo "W6 public surface governance: 4/4 PASS\n";
