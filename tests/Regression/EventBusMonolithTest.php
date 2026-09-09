<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/zef_framework_v2.5.0-beta1.php';
use Zef\Framework\Event\EventDispatcher;
use Zef\Framework\Event\EventContext;
final class H2MonolithEvent {}
$bus=new EventDispatcher(); $count=0;
$bus->listen(H2MonolithEvent::class, static function(H2MonolithEvent $event) use (&$count): void { ++$count; });
$bus->freeze();
for($i=0;$i<1000;++$i) $bus->dispatch(new H2MonolithEvent());
if($count!==1000) throw new RuntimeException('monolith event dispatch failed');
echo 'EventBusMonolithTest: PASS'.PHP_EOL;
