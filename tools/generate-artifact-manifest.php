<?php

declare(strict_types=1);

/**
 * ZEF Framework — Artifact Manifest Generator
 * =====================================================
 * Remediation R4-C / SC-02: generate dist/artifact-manifest.json
 * dengan field {artifact, version, sha256, build_time, composer_lock_hash}
 * sehingga CI dapat membaca nama artifact secara dinamis
 * tanpa hardcode di workflow file.
 *
 * Usage:
 *   php tools/generate-artifact-manifest.php
 *
 * Output:
 *   dist/artifact-manifest.json
 *
 * Dibaca oleh CI (sign-release job) dengan:
 *   ARTIFACT=$(php -r '
 *     $m = json_decode(file_get_contents("dist/artifact-manifest.json"), true);
 *     echo $m["artifact"] ?? "zef_framework_v2.5.0-beta1.php";
 *   ')
 * =====================================================
 */

$root = dirname(__DIR__);
$distDir = $root . '/dist';

if (!is_dir($distDir)) {
    mkdir($distDir, 0755, true);
}

// Cari artifact utama: zef_framework_*.php di root atau dist/
$artifact = null;
$artifactPath = null;

$candidates = array_merge(
    glob($root . '/zef_framework_*.php') ?: [],
    glob($distDir . '/zef_framework_*.php') ?: []
);

foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $artifact = basename($candidate);
        $artifactPath = $candidate;
        break;
    }
}

if ($artifact === null || $artifactPath === null) {
    // Fallback ke nama kanonik bila belum di-build
    $artifact = 'zef_framework_v2.5.0-beta1.php';
    $artifactPath = $root . '/' . $artifact;
    fwrite(STDERR, "[generate-artifact-manifest] WARNING: artifact not found, using canonical name.\n");
}

$sha256 = is_file($artifactPath) ? hash_file('sha256', $artifactPath) : null;

$lockHash = hash_file('sha256', $root . '/composer.lock');
if ($lockHash === false) {
    throw new RuntimeException('Cannot hash composer.lock.');
}

// Baca versi dari composer.json bila ada
$version = '2.5.0-beta1';
$composerJsonPath = $root . '/composer.json';
if (is_file($composerJsonPath)) {
    $decoded = json_decode((string) file_get_contents($composerJsonPath), true);
    if (is_array($decoded) && isset($decoded['version']) && is_string($decoded['version'])) {
        $version = ltrim($decoded['version'], 'v');
    }
}

$manifest = [
    'artifact'           => $artifact,
    'version'            => $version,
    'sha256'             => $sha256,
    'build_time'         => date('c'),
    'composer_lock_hash' => $lockHash,
    'generator'          => 'zef-artifact-manifest/1.0.0',
];

$output = $distDir . '/artifact-manifest.json';
file_put_contents(
    $output,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "Artifact manifest generated: {$output}\n";
echo "  artifact: {$artifact}\n";
echo "  version:  {$version}\n";
echo "  sha256:   " . ($sha256 ?? '(artifact not built yet)') . "\n";
