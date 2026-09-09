<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use Zef\Framework\Application;
use Zef\Framework\Event\EventBusInterface;
use Zef\Framework\Event\EventContext;
final class H2IntegrationEvent { public function __construct(public readonly string $value) {} }
$app=new Application();
/** @var EventBusInterface $bus */
$bus=$app->getContainer()->get(EventBusInterface::class);
$seen=[];
$bus->listen(H2IntegrationEvent::class, static function(H2IntegrationEvent $event, EventContext $context) use (&$seen): void { $seen=[$event->value,$context->correlationId]; });
$app->boot();
// Registration is intentionally frozen at application boot; listener above must remain usable after boot.
$bus->dispatchWithContext(new H2IntegrationEvent('ok'), new EventContext(str_repeat('c',16), (int)(microtime(true)*1000000000), 'corr-h2-01'));
if ($seen !== ['ok','corr-h2-01']) throw new RuntimeException('event bus did not dispatch through application container');
echo 'EventBusIntegrationTest: PASS'.PHP_EOL;
