<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

$app = new \Zef\Framework\Application(false);
$app->boot();
$cache = $app->getContainer()->get(\Zef\Framework\Cache\CacheInterface::class);
if (!$cache instanceof \Zef\Framework\Cache\CacheInterface) { fwrite(STDERR,"CacheInterface was not resolvable\n"); exit(1); }
$cache->set('app:local', 'worker-local', 60);
if ($cache->get('app:local') !== 'worker-local') { fwrite(STDERR,"container cache read/write failed\n"); exit(1); }
$app->shutdown();

echo "CacheApplicationIntegrationTest: PASS\n";
