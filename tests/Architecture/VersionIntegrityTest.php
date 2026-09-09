<?php
declare(strict_types=1);
require __DIR__.'/_assert.php';
$root=dirname(__DIR__,2);
$s=architecture_source($root);
preg_match("/public const VERSION\s*=\s*'([^']+)'/",$s,$m);
$v=$m[1]??null;
architecture_check($v==='2.5.0-beta1','runtime version authority is current beta1');
architecture_check(str_contains($s,"'version'=>\\Zef\\Framework\\Foundation\\ZefVersion::VERSION"),'about endpoint uses version authority');
