<?php

declare(strict_types=1);

/** @return array{hardViolations:list<string>,advisories:list<string>} */
function scanEnterpriseStaticPolicy(string $root): array
{
    $hardViolations = [];
    $advisories = [];
    $forbiddenFunctions = ['eval', 'shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec'];
    $sourceRoot = rtrim($root, '/').'/src';
    if (!is_dir($sourceRoot)) {
        return ['hardViolations'=>['Missing production source root: '.$sourceRoot], 'advisories'=>[]];
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') continue;
        $path = $file->getPathname();
        $relative = str_replace(rtrim($root, '/').'/', '', $path);
        $tokens = token_get_all((string) file_get_contents($path));
        foreach ($tokens as $token) {
            if (!is_array($token)) continue;
            [$id, $text, $line] = $token;
            if ($id === T_GLOBAL) {
                $hardViolations[] = "$relative:$line global statement is forbidden in production source";
            } elseif ($id === T_STRING && in_array(strtolower($text), $forbiddenFunctions, true)) {
                $hardViolations[] = "$relative:$line forbidden process/dynamic execution function: $text";
            } elseif ($id === T_STRING && strtolower($text) === 'sleep') {
                $hardViolations[] = "$relative:$line blocking sleep() is forbidden in production source";
            } elseif ($id === T_STRING && strtolower($text) === 'usleep' && $relative !== 'src/Framework/Runtime/BlockingSleeper.php') {
                $hardViolations[] = "$relative:$line usleep() is only permitted inside BlockingSleeper boundary";
            }
        }
    }
    return ['hardViolations'=>$hardViolations, 'advisories'=>$advisories];
}
