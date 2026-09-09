<?php
declare(strict_types=1);

/**
 * Executable architecture policy for Zef Framework.
 *
 * The policy operates on canonical modular source files only. It checks actual
 * PHP namespace imports and fully-qualified Zef references, avoiding brittle
 * substring-only checks while remaining dependency-free.
 */

$root = dirname(__DIR__);
$src = $root . '/src';
$failures = [];

/** @return list<string> */
function phpFiles(string $root): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

/** @return list<string> */
function zefDependencies(string $code): array
{
    $tokens = token_get_all($code);
    $deps = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }
        if (!in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
            continue;
        }
        $value = $token[1];
        if ($value === '') {
            continue;
        }
        if (str_starts_with($value, 'Zef\\')) {
            $deps[] = ltrim($value, '\\');
        }
    }
    return array_values(array_unique($deps));
}

function layerFor(string $path): string
{
    $rel = str_replace('\\', '/', $path);
    $pos = strpos($rel, '/src/');
    $rel = $pos === false ? $rel : substr($rel, $pos + 5);
    $parts = explode('/', $rel);
    return match ($parts[0] ?? '') {
        'Framework' => match ($parts[1] ?? '') {
            'Application' => 'Application',
            'Container' => 'Container',
            'Http' => 'Http',
            'Middleware' => 'Middleware',
            'Observability' => 'Observability',
            'Policy' => 'Policy',
            'Router' => 'Router',
            'Runtime' => 'Runtime',
            'Security' => 'Security',
            'Validation' => 'Validation',
            'Config' => 'Config',
            'Constant' => 'Constant',
            'Exception' => 'Exception',
            'Foundation' => 'Foundation',
            'Event' => 'Event',
            'CQRS' => 'CQRS',
            'Job' => 'Job',
            'Cache' => 'Cache',
            default => 'Framework',
        },
        'Middleware' => 'ApplicationMiddleware',
        'App' => 'App',
        'Module' => 'Module',
        'Plugin' => 'Plugin',
        'Test' => 'Test',
        default => 'Other',
    };
}

$forbidden = [
    'Container' => ['Http','Router','Middleware','Observability','Security','Runtime','Application','App','Module','Plugin','Test'],
    'Router' => ['Http','Container','Middleware','Observability','Security','Runtime','Application','App','Module','Plugin','Test'],
    'Http' => ['Container','Router','Middleware','Observability','Security','Runtime','Application','App','Module','Plugin','Test'],
    'Validation' => ['Container','Router','Http','Middleware','Observability','Security','Runtime','Application','App','Module','Plugin','Test'],
    'Observability' => ['Container','Router','Http','Middleware','Security','Runtime','Application','App','Module','Plugin','Test'],
    'Policy' => ['Container','Router','Http','Middleware','Observability','Security','Runtime','Application','App','Module','Plugin','Test'],
    'Constant' => ['Container','Router','Http','Middleware','Observability','Security','Runtime','Application','App','Module','Plugin','Test'],
    'Foundation' => ['Container','Router','Http','Middleware','Observability','Security','Runtime','Application','App','Module','Plugin','Test'],
    'Event' => ['Container','Router','Http','Middleware','Observability','Security','Runtime','Application','Module','Plugin','Test'],
    'CQRS' => ['Job','Container','Router','Http','Middleware','Observability','Security','Runtime','Application','App','Module','Plugin','Test'],
    'Config' => ['Container','Router','Http','Middleware','Observability','Security','Runtime','Application','App','Module','Plugin','Test'],
    'Exception' => ['Container','Router','Http','Middleware','Observability','Security','Runtime','Application','App','Module','Plugin','Test'],
    'Cache' => ['Container','Router','Http','Middleware','Observability','Security','Runtime','Application','App','Module','Plugin','Test'],
    'Runtime' => ['Module','Plugin','Test'],
    'Security' => ['Runtime','Application','App','Module','Plugin','Test'],
    'ApplicationMiddleware' => ['Container','Router','Application','App','Module','Plugin','Test'],
    'Application' => ['App','Module','Plugin','Test'],
    'Framework' => ['App','Module','Plugin','Test'],
];

foreach (phpFiles($src) as $file) {
    $code = file_get_contents($file);
    if ($code === false) {
        $failures[] = "unable to read {$file}";
        continue;
    }
    $layer = layerFor($file);
    foreach (zefDependencies($code) as $dependency) {
        $depLayer = match (true) {
            str_starts_with($dependency, 'Zef\\Framework\\Application') => 'Application',
            str_starts_with($dependency, 'Zef\\Framework\\Container') => 'Container',
            str_starts_with($dependency, 'Zef\\Framework\\Http') => 'Http',
            str_starts_with($dependency, 'Zef\\Framework\\Middleware') => 'Middleware',
            str_starts_with($dependency, 'Zef\\Framework\\Observability') => 'Observability',
            str_starts_with($dependency, 'Zef\\Framework\\Policy') => 'Policy',
            str_starts_with($dependency, 'Zef\\Framework\\Router') => 'Router',
            str_starts_with($dependency, 'Zef\\Framework\\Runtime') => 'Runtime',
            str_starts_with($dependency, 'Zef\\Framework\\Security') => 'Security',
            str_starts_with($dependency, 'Zef\\Framework\\Validation') => 'Validation',
            str_starts_with($dependency, 'Zef\\Framework\\Config') => 'Config',
            str_starts_with($dependency, 'Zef\\Framework\\Constant') => 'Constant',
            str_starts_with($dependency, 'Zef\\Framework\\Exception') => 'Exception',
            str_starts_with($dependency, 'Zef\\Framework\\Foundation') => 'Foundation',
            str_starts_with($dependency, 'Zef\\Framework\\Event') => 'Event',
            str_starts_with($dependency, 'Zef\\Framework\\CQRS') => 'CQRS',
            str_starts_with($dependency, 'Zef\\Framework\\Job') => 'Job',
            str_starts_with($dependency, 'Zef\\App\\') => 'App',
            str_starts_with($dependency, 'Zef\\Module\\') => 'Module',
            str_starts_with($dependency, 'Zef\\Plugin\\') => 'Plugin',
            str_starts_with($dependency, 'Zef\\Test\\') => 'Test',
            default => 'Other',
        };
        if ($layer === $depLayer || $depLayer === 'Other') {
            continue;
        }
        if (in_array($depLayer, $forbidden[$layer] ?? [], true)) {
            $failures[] = sprintf('%s -> %s via %s', $layer, $depLayer, $dependency);
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Architecture policy: PASS\n";
