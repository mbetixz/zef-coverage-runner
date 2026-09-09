<?php
declare(strict_types=1);
$root=dirname(__DIR__); $manifest=$root.'/supply-chain/SHA256SUMS-i.txt'; if(!is_file($manifest)){fwrite(STDERR,"Missing manifest\n");exit(1);} $fail=[]; $lines=file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); if($lines===false){fwrite(STDERR,"Cannot read manifest\n");exit(1);} foreach($lines as $line){$parts=preg_split('/\s+/',trim($line),2); if(!is_array($parts)||count($parts)!==2) {$fail[]='Malformed manifest line';continue;} [$hash,$file]=$parts; $path=$root.'/'.$file; if(!is_file($path)){$fail[]='Missing: '.$file;continue;} $actual=hash_file('sha256',$path); if($actual===false||!hash_equals($hash,$actual))$fail[]='Hash mismatch: '.$file;} if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);} $canonical = $root.'/zef_framework_v2.5.0-beta1.php';
$dist = $root.'/dist/zef_framework_v2.5.0-beta1-phase5.php';
if (!is_file($canonical) || !is_file($dist)) {
    $fail[] = 'Canonical/dist artifact missing for BD-04 verification';
} else {
    $canonicalHash = hash_file('sha256', $canonical);
    $distHash = hash_file('sha256', $dist);
    if ($canonicalHash === false || $distHash === false || !hash_equals($canonicalHash, $distHash)) {
        $fail[] = 'BD-04 violation: canonical monolith differs from dist artifact';
    }
}
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Release integrity: PASS\n";
