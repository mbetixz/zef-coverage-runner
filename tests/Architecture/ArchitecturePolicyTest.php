<?php
declare(strict_types=1);
require __DIR__ . '/_assert.php';
$root = dirname(__DIR__, 2);
$output = [];
$rc = 0;
exec(PHP_BINARY . ' ' . escapeshellarg($root . '/tools/architecture-policy.php'), $output, $rc);
architecture_check($rc === 0, 'executable architecture dependency policy');
echo implode(PHP_EOL, $output) . PHP_EOL;
