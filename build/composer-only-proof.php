<?php
declare(strict_types=1);

/**
 * D3/D4 deterministic composer-only resolution proof.
 * Scans every PHP file under src/ for declared Zef types (token-accurate)
 * and resolves each FQCN using ONLY vendor/autoload.php (no src/autoload.php).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__) . '/src';
$it   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$decls = [];

foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $code = file_get_contents($f->getPathname());
    $tokens = token_get_all($code);
    $count = count($tokens);
    $ns = '';
    $i = 0;
    while ($i < $count) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_NAMESPACE) {
            // collect namespace name until ; or {
            $ns = '';
            ++$i;
            while ($i < $count) {
                $x = $tokens[$i];
                if (is_array($x)) {
                    $ns .= $x[1];
                } elseif ($x === ';' || $x === '{') {
                    break;
                }
                ++$i;
            }
            $ns = trim($ns);
        } elseif (is_array($t) && in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            // skip ::class / anonymous class constructions handled by peek
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                ++$j;
            }
            $nameTok = $tokens[$j] ?? null;
            if (is_array($nameTok) && in_array($nameTok[0], [T_STRING], true)) {
                $decls[] = ($ns !== '' ? $ns . '\\' : '') . $nameTok[1];
            }
        }
        ++$i;
    }
}

$uniq = array_values(array_unique($decls));
$zef  = array_values(array_filter($uniq, static fn(string $fqcn): bool => str_starts_with($fqcn, 'Zef')));

$resolved = 0;
$unresolved = [];
$errors = [];

foreach ($zef as $fqcn) {
    try {
        if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn)) {
            ++$resolved;
        } else {
            $unresolved[] = $fqcn;
        }
    } catch (\Throwable $e) {
        $errors[] = $fqcn . ' => ' . $e->getMessage();
    }
}

echo 'declared Zef types (token-accurate): ' . count($zef) . PHP_EOL;
echo 'resolved composer-only:             ' . $resolved . PHP_EOL;
echo 'UNRESOLVED (' . count($unresolved) . '):' . PHP_EOL . implode(PHP_EOL, $unresolved) . PHP_EOL;
if ($errors !== []) {
    echo 'ERRORS (' . count($errors) . '):' . PHP_EOL . implode(PHP_EOL, $errors) . PHP_EOL;
}

exit($unresolved === [] && $errors === [] ? 0 : 1);
