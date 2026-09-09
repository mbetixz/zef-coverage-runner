<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$evidence = $root . '/evidence/Q8_5';
if (!is_dir($evidence) && !mkdir($evidence, 0775, true) && !is_dir($evidence)) {
    fwrite(STDERR, "Q8.5 qualification FAILED: unable to create evidence directory.\n");
    exit(2);
}

$commands = [
    'failure_security' => 'php vendor/bin/phpunit tests/Qualification/G4_8Q8_5FailureSecurityQualificationTest.php',
    'architecture' => 'php tests/Architecture/run-all.php',
    'regression' => 'php tests/Regression/run-all.php',
];

$summary = [
    'gate' => 'Q8.5',
    'runtime' => PHP_VERSION,
    'commit' => getenv('GITHUB_SHA') ?: 'local',
    'runner' => 'local_or_ci',
    'started_at_utc' => gmdate('c'),
    'commands' => [],
];

foreach ($commands as $name => $command) {
    $output = [];
    $exitCode = 0;
    exec('cd ' . escapeshellarg($root) . ' && ' . $command . ' 2>&1', $output, $exitCode);
    file_put_contents($evidence . '/' . $name . '.log', implode(PHP_EOL, $output) . PHP_EOL);
    $summary['commands'][$name] = [
        'command' => $command,
        'exit_code' => $exitCode,
        'result' => $exitCode === 0 ? 'PASS' : 'FAIL',
    ];
    if ($exitCode !== 0) {
        $summary['result'] = 'FAIL';
        $summary['completed_at_utc'] = gmdate('c');
        file_put_contents($evidence . '/q8-5-summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit($exitCode);
    }
}

$summary['result'] = 'PASS';
$summary['completed_at_utc'] = gmdate('c');
file_put_contents($evidence . '/q8-5-summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo "Q8.5 failure/security qualification PASS\n";
