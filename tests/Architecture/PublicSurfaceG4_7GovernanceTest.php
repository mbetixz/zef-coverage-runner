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
$baseRaw=json_decode((string)file_get_contents($root.'/tests/Architecture/public-surface-baseline-beta1-g4_3.json'),true,512,JSON_THROW_ON_ERROR);
$deltaRaw=json_decode((string)file_get_contents($root.'/tests/Architecture/public-surface-delta-v2.5.0-beta1-g4.7.json'),true,512,JSON_THROW_ON_ERROR);
if (!is_array($baseRaw) || !isset($baseRaw['classes'],$baseRaw['methods']) || !is_array($baseRaw['classes']) || !is_array($baseRaw['methods'])) throw new RuntimeException('Invalid G4.3 public surface baseline.');
if (!is_array($deltaRaw) || !isset($deltaRaw['added_classes'],$deltaRaw['removed_classes'],$deltaRaw['added_public_methods'],$deltaRaw['removed_public_methods']) || !is_array($deltaRaw['added_classes']) || !is_array($deltaRaw['removed_classes']) || !is_array($deltaRaw['added_public_methods']) || !is_array($deltaRaw['removed_public_methods'])) throw new RuntimeException('Invalid G4.7 public surface delta.');
$base=$baseRaw; $delta=$deltaRaw;
/** @var list<string> $baseClasses */
$baseClasses=$base['classes'];
/** @var list<string> $baseMethods */
$baseMethods=$base['methods'];
/** @var list<string> $addedClassesDeclared */
$addedClassesDeclared=$delta['added_classes'];
/** @var list<string> $removedClassesDeclared */
$removedClassesDeclared=$delta['removed_classes'];
/** @var list<string> $addedMethodsDeclared */
$addedMethodsDeclared=$delta['added_public_methods'];
/** @var list<string> $removedMethodsDeclared */
$removedMethodsDeclared=$delta['removed_public_methods'];
[$classes,$methods]=$extract($s);
$addedClasses=array_values(array_diff($classes,$baseClasses)); sort($addedClasses);
$removedClasses=array_values(array_diff($baseClasses,$classes)); sort($removedClasses);
$addedMethods=array_values(array_diff($methods,$baseMethods)); sort($addedMethods);
$removedMethods=array_values(array_diff($baseMethods,$methods)); sort($removedMethods);
architecture_check($addedClassesDeclared===$addedClasses,'G4.7 public class additions match declared delta');
architecture_check($removedClassesDeclared===$removedClasses,'G4.7 public class removals match declared delta');
architecture_check($addedMethodsDeclared===$addedMethods,'G4.7 public method additions match declared delta');
architecture_check($removedMethodsDeclared===$removedMethods,'G4.7 public method removals match declared delta');
echo "PublicSurfaceG4_7GovernanceTest: PASS\n";
