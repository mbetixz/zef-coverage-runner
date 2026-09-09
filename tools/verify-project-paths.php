<?php
declare(strict_types=1);

/**
 * Zef project-path topology verifier.
 *
 * The repository root is authoritative: this file must live in <root>/tools.
 * All release tooling must resolve project paths from __DIR__/.. rather than
 * the caller's current working directory or machine-specific absolute paths.
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Project path verification: unable to resolve project root.\n");
    exit(2);
}

$requiredDirectories = [
    'src', 'tests', 'tools', 'build', 'docs', 'supply-chain', 'dist',
];
$requiredFiles = [
    'composer.json',
    'composer.lock',
    'quality-gate.php',
    'phpstan.neon.dist',
    'ROADMAP.md',
    'PROJECT_HANDOVER.md',
];

$failures = [];

foreach ($requiredDirectories as $directory) {
    if (!is_dir($root . '/' . $directory)) {
        $failures[] = 'Missing canonical directory: ' . $directory;
    }
}
foreach ($requiredFiles as $file) {
    if (!is_file($root . '/' . $file)) {
        $failures[] = 'Missing canonical file: ' . $file;
    }
}

$scanRoots = ['tools', 'build', 'tests'];
$extensions = ['php', 'sh', 'yml', 'yaml', 'neon'];
foreach ($scanRoots as $scanRoot) {
    $base = $root . '/' . $scanRoot;
    if (!is_dir($base)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        if (!in_array($file->getExtension(), $extensions, true)) {
            continue;
        }
        if (realpath($file->getPathname()) === realpath(__FILE__)) {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            $failures[] = 'Unable to read: ' . $file->getPathname();
            continue;
        }
        $machinePathMarkers = ['/mnt' . '/data/', '/workspace/'];
        if (str_contains($content, $machinePathMarkers[0]) || str_contains($content, $machinePathMarkers[1])) {
            $failures[] = 'Machine-specific absolute path found: ' . $file->getPathname();
        }
        if ($file->getExtension() === 'php' && preg_match('/\bget' . 'cwd\s*\(/', $content) === 1) {
            $failures[] = 'getcwd() used for project-path resolution: ' . $file->getPathname();
        }
    }
}

$pathTools = [
    'quality-gate.php',
    'build/build-monolith.php',
    'tools/documentation-consistency.php',
    'tools/verify-baseline-drift.php',
    'tools/verify-composer-lock.php',
    'tools/verify-supply-chain.php',
    'tools/verify-release-integrity.php','tools/generate-full-sha256.php','tools/verify-full-sha256.php',
];
foreach ($pathTools as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        $failures[] = 'Path-governed tool missing: ' . $relative;
        continue;
    }
    $content = file_get_contents($path);
    if ($content === false) {
        $failures[] = 'Unable to read path-governed tool: ' . $relative;
        continue;
    }
    if ($relative !== 'quality-gate.php' && !str_contains($content, 'dirname(__DIR__)') && !str_contains($content, "dirname(__DIR__, 2)")) {
        $failures[] = 'Tool does not derive root from its location: ' . $relative;
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Project path topology: PASS\n";
echo "Canonical root: {$root}\n";
exit(0);
