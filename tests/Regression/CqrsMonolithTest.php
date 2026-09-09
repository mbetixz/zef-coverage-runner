<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/zef_framework_v2.5.0-beta1.php';
$bus=new \Zef\Framework\CQRS\QueryBus();
final class H1MonolithQuery {}
$bus->register(H1MonolithQuery::class, static fn(H1MonolithQuery $q, \Zef\Framework\CQRS\CqrsContext $ctx): string => 'monolith-ok');
$bus->freeze();
if($bus->ask(new H1MonolithQuery()) !== 'monolith-ok') throw new RuntimeException('H1 CQRS missing or broken in monolith.');
echo "CqrsMonolithTest: PASS\n";
