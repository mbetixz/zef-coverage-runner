<?php
declare(strict_types=1);
require __DIR__.'/_assert.php';
$root=dirname(__DIR__,2);
$s=file_get_contents($root.'/zef_framework_v2.5.0-beta1.php');
if($s===false){throw new RuntimeException('Cannot read monolith');}
preg_match("/public const VERSION\s*=\s*'([^']+)'/",$s,$m);
$v=$m[1]??null;
architecture_check($v==='2.5.0-beta1','runtime version authority is current beta1');
$about=strpos($s,"'version'=>\\Zef\\Framework\\Foundation\\ZefVersion::VERSION");
architecture_check($about!==false,'about endpoint references version authority');
$banner=strpos($s,'echo "ZEF Framework v".\\Zef\\Framework\\Foundation\\ZefVersion::VERSION');
architecture_check($banner!==false,'self-test banner references version authority');
