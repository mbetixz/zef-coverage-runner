<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

$source=file_get_contents(dirname(__DIR__,2).'/src/Framework/Job/Jobs.php');
if ($source===false) { fwrite(STDERR,"cannot read job source\n"); exit(1); }
foreach (['interface JobInterface','interface JobQueueInterface','interface JobHandlerInterface','interface JobMiddlewareInterface','interface JobIdempotencyStoreInterface'] as $needle) {
    if (!str_contains($source,$needle)) { fwrite(STDERR,"missing contract {$needle}\n"); exit(1); }
}
if (preg_match('/unserialize\s*\(|socket_connect|fsockopen|stream_socket|curl_exec/i',$source)===1) { fwrite(STDERR,"forbidden transport/security primitive in job foundation\n"); exit(1); }
echo "JobSystemGovernanceTest: PASS\n";
