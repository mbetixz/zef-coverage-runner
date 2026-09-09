<?php
declare(strict_types=1);
require __DIR__ . '/_assert.php';
require dirname(__DIR__,2).'/vendor/autoload.php';
use Zef\Framework\Event\EventDispatcher;
final class H2GovernanceEvent {}
$bus=new EventDispatcher();
architecture_check(array_key_exists(\Psr\EventDispatcher\EventDispatcherInterface::class, class_implements(EventDispatcher::class) ?: []),'event bus implements PSR-14 dispatcher contract');
architecture_check(array_key_exists(\Psr\EventDispatcher\ListenerProviderInterface::class, class_implements(EventDispatcher::class) ?: []),'event bus exposes PSR-14 listener provider');
$seen=[];
$bus->listen(H2GovernanceEvent::class, static function(object $event) use (&$seen): void { $seen[]='normal'; }, 0);
$bus->listen(H2GovernanceEvent::class, static function(object $event, \Zef\Framework\Event\EventContext $context) use (&$seen): void { $seen[]=$context->correlationId ?? 'none'; }, 10);
$bus->dispatchWithContext(new H2GovernanceEvent(), new \Zef\Framework\Event\EventContext(str_repeat('b',16), 1, 'corr-h2-01'));
architecture_check($seen===['corr-h2-01','normal'],'priority and context dispatch are deterministic');
$bus->freeze(); $failed=false; try{$bus->listen(H2GovernanceEvent::class, static function(){});}catch(LogicException){$failed=true;}
architecture_check($failed,'event registration freezes before runtime');
echo "EventBusGovernanceTest: PASS\n";
