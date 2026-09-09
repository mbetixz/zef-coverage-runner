<?php
declare(strict_types=1);
require __DIR__ . '/_assert.php';
require dirname(__DIR__,2).'/vendor/autoload.php';

$source=file_get_contents(dirname(__DIR__,2).'/src/Framework/Cache/Cache.php');
if ($source===false) { throw new RuntimeException('Cannot read cache source.'); }
foreach (['interface CacheInterface','interface CacheStoreInterface','interface CacheSerializerInterface','interface CacheKeyNormalizerInterface','interface CacheClockInterface'] as $needle) {
    architecture_check(str_contains($source,$needle), $needle.' exists');
}
architecture_check(!str_contains($source,'unserialize('), 'Cache foundation forbids PHP unserialization');
architecture_check(!preg_match('/socket_connect|fsockopen|stream_socket|curl_exec|https?:\\/\\//i',$source), 'Cache foundation has no network primitives');
architecture_check(!preg_match('/redis|memcached|kafka|rabbitmq|nats|sqs/i',$source), 'Cache foundation has no external infrastructure coupling');
architecture_check(str_contains($source,'final class InMemoryCache'), 'local cache implementation exists');
architecture_check(str_contains($source,'final class InMemoryCacheStore'), 'local store implementation exists');
echo "CacheSystemGovernanceTest: PASS\n";
