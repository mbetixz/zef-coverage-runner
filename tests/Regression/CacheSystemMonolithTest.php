<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/zef_framework_v2.5.0-beta1.php';
foreach ([
    'Zef\\Framework\\Cache\\CacheInterface',
    'Zef\\Framework\\Cache\\CacheStoreInterface',
    'Zef\\Framework\\Cache\\CacheSerializerInterface',
    'Zef\\Framework\\Cache\\CacheKeyNormalizerInterface',
    'Zef\\Framework\\Cache\\CacheClockInterface',
    'Zef\\Framework\\Cache\\InMemoryCache',
    'Zef\\Framework\\Cache\\InMemoryCacheStore',
] as $class) {
    if (!interface_exists($class) && !class_exists($class)) { fwrite(STDERR,"missing {$class}\n"); exit(1); }
}
$store = new \Zef\Framework\Cache\InMemoryCacheStore(2);
$cache = new \Zef\Framework\Cache\InMemoryCache($store);
$cache->set('mono:key','ok');
if ($cache->get('mono:key') !== 'ok') { fwrite(STDERR,"monolith cache behavior failed\n"); exit(1); }
echo "CacheSystemMonolithTest: PASS\n";
