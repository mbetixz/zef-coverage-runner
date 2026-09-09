<?php

declare(strict_types=1);

if (!function_exists('architecture_check')) {
    function architecture_check(bool $ok, string $label): void
    {
        if (!$ok) {
            throw new RuntimeException($label);
        }

        echo "PASS: {$label}\n";
    }
}

if (!function_exists('architecture_source')) {
    function architecture_source(string $root): string
    {
        $source = file_get_contents($root . '/zef_framework_v2.5.0-beta1.php');
        if ($source === false) {
            throw new RuntimeException('Cannot read monolith');
        }

        return $source;
    }
}
