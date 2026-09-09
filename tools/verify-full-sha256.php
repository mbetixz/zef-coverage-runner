<?php
declare(strict_types=1);
$root=dirname(__DIR__); $manifest=$root.'/SHA256SUMS.txt';
if(!is_file($manifest)){fwrite(STDERR,"Missing SHA256SUMS.txt\n");exit(1);}
$fail=[]; $lines=file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if($lines===false) throw new RuntimeException('Cannot read full SHA256 manifest.');
foreach($lines as $line){$parts=preg_split('/\s+/',trim($line),2);if(!is_array($parts)||count($parts)!==2){$fail[]='Malformed line';continue;}[$expected,$relative]=$parts;$path=$root.'/'.$relative;if($relative==='SHA256SUMS.txt'){$fail[]='Manifest must not self-reference';continue;}if(!is_file($path)){$fail[]='Missing: '.$relative;continue;}$actual=hash_file('sha256',$path);if($actual===false||!hash_equals($expected,$actual))$fail[]='Hash mismatch: '.$relative;}
if($fail){foreach(array_slice($fail,0,50) as $f)fwrite(STDERR,"FAIL: $f\n");if(count($fail)>50)fwrite(STDERR,'... '.(count($fail)-50).' more'.PHP_EOL);exit(1);}echo 'Full SHA256 verification: PASS ('.count($lines).' entries)'.PHP_EOL;
