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
$baseline=json_decode((string)file_get_contents($root.'/tests/Architecture/public-surface-baseline-beta1-m.json'),true,512,JSON_THROW_ON_ERROR);
/** @var array{from:string,to:string,added_classes:list<string>,removed_classes:list<string>,added_public_methods:list<string>,removed_public_methods:list<string>} $delta */
$delta=json_decode((string)file_get_contents($root.'/tests/Architecture/public-surface-delta-v2.5.0-beta1-l2.json'),true,512,JSON_THROW_ON_ERROR);
[$classes,$methods]=$extract($s);
$addedClasses=array_values(array_diff($classes,$baseline['classes'])); sort($addedClasses);
$removedClasses=array_values(array_diff($baseline['classes'],$classes)); sort($removedClasses);
$addedMethods=array_values(array_diff($methods,$baseline['methods'])); sort($addedMethods);
$removedMethods=array_values(array_diff($baseline['methods'],$methods)); sort($removedMethods);
architecture_check($delta['added_classes']===$addedClasses,'L2 public class additions match declared delta');
architecture_check($delta['removed_classes']===$removedClasses,'L2 public class removals match declared delta');
architecture_check($delta['added_public_methods']===$addedMethods,'L2 public method additions match declared delta');
architecture_check($delta['removed_public_methods']===$removedMethods,'L2 public method removals match declared delta');
echo "PublicSurfaceL2GovernanceTest: PASS\n";
