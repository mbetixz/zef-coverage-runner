<?php
declare(strict_types=1);

$required = ['dom', 'mbstring', 'xmlwriter', 'filter', 'json', 'libxml', 'tokenizer'];
$missing = array_values(array_filter($required, static fn (string $extension): bool => !extension_loaded($extension)));
if ($missing !== []) {
    fwrite(STDERR, 'Q8.5 qualification preflight FAILED: missing extensions: ' . implode(',', $missing) . PHP_EOL);
    exit(2);
}
if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, "Q8.5 qualification preflight FAILED: PHP >= 8.4 required.\n");
    exit(3);
}
echo 'Q8.5 qualification preflight PASS' . PHP_EOL;
