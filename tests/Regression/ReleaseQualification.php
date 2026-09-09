<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
passthru(PHP_BINARY.' '.escapeshellarg($root.'/tests/Regression/run-all.php'),$rc);
exit($rc);
