<?php
declare(strict_types=1); $rc=0; passthru(PHP_BINARY.' '.escapeshellarg(__DIR__.'/run-all.php'),$rc); exit($rc);
