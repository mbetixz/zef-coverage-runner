<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/tools/quality/StaticPolicyScanner.php';

$root = sys_get_temp_dir().'/zef-static-policy-'.bin2hex(random_bytes(4));
mkdir($root.'/src/Framework/Runtime', 0777, true);
register_shutdown_function(static function () use ($root): void {
    if (!is_dir($root)) return;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo) continue;
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
});

file_put_contents($root.'/src/Framework/Runtime/BlockingSleeper.php', '<?php usleep(1000);');
file_put_contents($root.'/src/Unsafe.php', '<?php sleep(1); usleep(1); exec("bad");');
/** @var array{hardViolations:list<string>,advisories:list<string>} $result */
/** @phpstan-ignore function.notFound */
$result = scanEnterpriseStaticPolicy($root);
$messages = implode("\n", $result['hardViolations']);
$pass = count($result['hardViolations']) === 3
    && str_contains($messages, 'sleep() is forbidden')
    && str_contains($messages, 'usleep() is only permitted')
    && str_contains($messages, 'forbidden process/dynamic execution');
if (!$pass) {
    fwrite(STDERR, "FAIL: static policy classification\n".$messages."\n");
    exit(1);
}
echo "PASS: approved BlockingSleeper boundary is allowed\n";
echo "PASS: sleep/usleep/process execution are hard failures\n";
echo "Static policy characterization: 2 pass, 0 fail\n";
