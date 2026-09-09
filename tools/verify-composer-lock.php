<?php
declare(strict_types=1);

$root = dirname(__DIR__);
/** @var array<string,mixed> $composer */
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
/** @var array<string,mixed> $lock */
$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);

/** @var array<string,string> $required */
$required = is_array($composer['require'] ?? null) ? $composer['require'] : [];
/** @var array<string,string> $devRequired */
$devRequired = is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [];
$locked = [];
$packages = is_array($lock['packages'] ?? null) ? $lock['packages'] : [];
$packagesDev = is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [];
foreach (array_merge($packages, $packagesDev) as $package) {
    if (!is_array($package)) continue;
    $name = $package['name'] ?? null;
    $version = $package['version'] ?? null;
    if (is_string($name) && is_string($version)) $locked[$name] = $version;
}

$missing = [];
foreach (array_keys($required + $devRequired) as $name) {
    if ($name === 'php' || !isset($locked[$name])) {
        if ($name !== 'php') {
            $missing[] = $name;
        }
    }
}

if ($missing !== []) {
    fwrite(STDERR, 'Missing locked packages: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

$keys = ['name','version','require','require-dev','conflict','replace','provide','minimum-stability','prefer-stable','repositories','extra'];
$relevant = [];
foreach ($keys as $key) {
    if (array_key_exists($key, $composer)) {
        $relevant[$key] = $composer[$key];
    }
}
$composerConfig = is_array($composer['config'] ?? null) ? $composer['config'] : [];
if (isset($composerConfig['platform']) && is_array($composerConfig['platform'])) {
    $relevant['config'] = ['platform' => $composerConfig['platform']];
}
ksort($relevant);
$json = json_encode($relevant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) throw new RuntimeException('Unable to encode Composer metadata.');
$json = str_replace('/', '\\/', $json);
$hash = md5($json);

$contentHash = $lock['content-hash'] ?? null;
if (!is_string($contentHash) || $contentHash !== $hash) {
    fwrite(STDERR, 'Composer content-hash mismatch: expected '.$hash.', actual '.(is_string($contentHash) ? $contentHash : '<invalid>').PHP_EOL);
    exit(1);
}

$lockPlatform = is_array($lock['platform'] ?? null) ? $lock['platform'] : [];
if (($lockPlatform['php'] ?? null) !== ($required['php'] ?? null)) {
    fwrite(STDERR, 'Locked PHP platform does not match composer.json.' . PHP_EOL);
    exit(1);
}

echo "Composer lock structural verification: PASS\n";
echo "PHPStan locked: " . ($locked['phpstan/phpstan'] ?? 'NO') . "\n";
