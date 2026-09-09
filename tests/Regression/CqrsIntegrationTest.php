<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';

use Zef\Framework\Application;
use Zef\Framework\CQRS\CommandHandlerInterface;
use Zef\Framework\CQRS\CommandBusInterface;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\CQRS\QueryHandlerInterface;
use Zef\Framework\CQRS\QueryBusInterface;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventBusInterface;
use Zef\Framework\Config\AbstractModule;
use Zef\Framework\Config\ModuleContext;
use Zef\Framework\Config\ModuleDefinition;

final class H1CreateProduct {}
final class H1FindProduct { public function __construct(public readonly int $id) {} }
final class H1ProductCreated { public function __construct(public readonly int $id) {} }

final class H1CqrsModule extends AbstractModule {
    /** @var list<string> */ public static array $events = [];
    public function __construct() { parent::__construct(new ModuleDefinition('h1-cqrs')); }
    #[\Override] public function register(ModuleContext $context): void {
        /** @var CommandBusInterface $commands */ $commands=$context->container()->get(CommandBusInterface::class);
        /** @var QueryBusInterface $queries */ $queries=$context->container()->get(QueryBusInterface::class);
        /** @var EventBusInterface $events */ $events=$context->container()->get(EventBusInterface::class);
        $events->listen(H1ProductCreated::class, static function (H1ProductCreated $event, EventContext $ctx): void { self::$events[]=$event->id.':'.$ctx->correlationId; });
        $commands->register(H1CreateProduct::class, static fn(H1CreateProduct $command, CqrsContext $ctx): \Zef\Framework\CQRS\CqrsEventResult => new \Zef\Framework\CQRS\CqrsEventResult(['created'=>5], [new H1ProductCreated(5)]));
        $queries->register(H1FindProduct::class, static fn(H1FindProduct $query, CqrsContext $ctx): array => ['id'=>$query->id]);
    }
}

$app=new Application();
$app->addModule(new H1CqrsModule());
$app->boot();
$result=$app->getCommandBus()->dispatch(new H1CreateProduct(), new CqrsContext('corr-h1-app'));
if ($result !== ['created'=>5]) throw new RuntimeException('CQRS command integration failed.');
if (H1CqrsModule::$events !== ['5:corr-h1-app']) throw new RuntimeException('CQRS event integration failed.');
$query=$app->getQueryBus()->ask(new H1FindProduct(11), new CqrsContext('corr-h1-query'));
if ($query !== ['id'=>11]) throw new RuntimeException('CQRS query integration failed.');
if (!($commandBus=$app->getContainer()->get(CommandBusInterface::class)) instanceof CommandBusInterface || !$commandBus->isFrozen()) throw new RuntimeException('command bus must be frozen after application boot.');
if (!($queryBus=$app->getContainer()->get(QueryBusInterface::class)) instanceof QueryBusInterface || !$queryBus->isFrozen()) throw new RuntimeException('query bus must be frozen after application boot.');
$app->shutdown();
echo "CqrsIntegrationTest: PASS\n";
