<?php
declare(strict_types=1);
require __DIR__ . '/_assert.php';
$root=dirname(__DIR__,2);
$s=architecture_source($root);
$extract=static function(string $src):array{
    preg_match_all('/^\s*(?:(?:final|abstract|readonly) )*(?:class|interface)\s+([A-Za-z_][A-Za-z0-9_]*)/m',$src,$c);
    preg_match_all('/^\s*public function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m',$src,$m);
    $classes=array_values(array_unique($c[1])); sort($classes);
    $methods=array_values(array_unique($m[1])); sort($methods);
    return [$classes,$methods];
};
/** @var array{classes:list<string>,methods:list<string>} $baseline */
$baseline=json_decode((string)file_get_contents($root.'/tests/Architecture/public-surface-baseline-beta1-h3.json'),true,512,JSON_THROW_ON_ERROR);
/** @var array{from:string,to:string,added_classes:list<string>,removed_classes:list<string>,added_public_methods:list<string>,removed_public_methods:list<string>} $delta */
$delta=json_decode((string)file_get_contents($root.'/tests/Architecture/public-surface-delta-v2.5.0-beta1-m.json'),true,512,JSON_THROW_ON_ERROR);
[$classes,$methods]=$extract($s);
$addedClasses=array_values(array_diff($classes,$baseline['classes'])); sort($addedClasses);
$removedClasses=array_values(array_diff($baseline['classes'],$classes)); sort($removedClasses);
$addedMethods=array_values(array_diff($methods,$baseline['methods'])); sort($addedMethods);
$removedMethods=array_values(array_diff($baseline['methods'],$methods)); sort($removedMethods);
architecture_check($delta['added_classes']===$addedClasses,'M public class additions match declared delta');
architecture_check($delta['removed_classes']===$removedClasses,'M public class removals match declared delta');
architecture_check($delta['added_public_methods']===$addedMethods,'M public method additions match declared delta');
architecture_check($delta['removed_public_methods']===$removedMethods,'M public method removals match declared delta');
