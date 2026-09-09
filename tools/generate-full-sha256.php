<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$out = $root . '/SHA256SUMS.txt';
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) continue;
    $path = $file->getPathname();
    if ($path === $out) continue;
    $relative = ltrim(str_replace('\\','/', substr($path, strlen($root))), '/');
    if ($relative === 'supply-chain/SHA256SUMS-i.txt' || str_ends_with($relative, '_CANDIDATE_SHA256SUMS.txt') || str_starts_with($relative, 'var/')) continue;
    if (str_starts_with($relative, '.git/')) continue;
    $files[] = $relative;
}
sort($files, SORT_STRING);
$lines=[];
foreach ($files as $relative) {
    $hash=hash_file('sha256',$root.'/'.$relative);
    if ($hash===false) throw new RuntimeException('Cannot hash '.$relative);
    $lines[]=$hash.'  '.$relative;
}
file_put_contents($out, implode("\n",$lines)."\n");
echo 'Full SHA256 manifest generated: '.count($lines).' entries'.PHP_EOL;
