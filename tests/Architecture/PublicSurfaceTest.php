<?php
declare(strict_types=1);
require __DIR__.'/_assert.php';
$root=dirname(__DIR__,2);
$s=architecture_source($root);
$extract=function(string $src):array {
    preg_match_all('/^\s*(?:(?:final|abstract|readonly) )*(?:class|interface)\s+([A-Za-z_][A-Za-z0-9_]*)/m',$src,$c);
    preg_match_all('/^\s*public function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m',$src,$m);
    $classes=array_values(array_unique($c[1])); sort($classes);
    $methods=array_values(array_unique($m[1])); sort($methods);
    return [$classes,$methods];
};
$baselinePath=$root.'/tests/Architecture/public-surface-baseline-beta1-g4_7.json';
architecture_check(is_file($baselinePath),'G4.7 public surface baseline exists');
/** @var array{classes:list<string>,methods:list<string>} $baseline */
$baseline=json_decode((string)file_get_contents($baselinePath),true,512,JSON_THROW_ON_ERROR);
[$currentClasses,$currentMethods]=$extract($s);
architecture_check($currentClasses===$baseline['classes'],'G4.7 public class surface is stable');
architecture_check($currentMethods===$baseline['methods'],'G4.7 public method surface is stable');
