<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventDispatchException;
use Zef\Framework\Event\EventDispatcher;
use Zef\Framework\Event\EventSubscriberInterface;

final class OrderCreated { public function __construct(public readonly int $id) {} }
final class OrderSubscriber implements EventSubscriberInterface {
    /** @var list<string> */ public static array $calls = [];
    #[\Override]
    public static function subscriptions(): array {
        return [OrderCreated::class => [[10, static function(OrderCreated $event, EventContext $context): void { self::$calls[] = 'high:'.$event->id; }], [0, static function(OrderCreated $event, EventContext $context): void { self::$calls[] = 'normal:'.$event->id; }]]];
    }
}

final class EventBusTest extends TestCase
{
    public function testLegacyBehavior(): void
    {
        $assert = static function(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };
        $bus = new EventDispatcher();
        $calls = [];
        $bus->listen(OrderCreated::class, static function(OrderCreated $event, EventContext $context) use (&$calls): void { $calls[]='first:'.$event->id; if ($context->correlationId !== 'corr-001') throw new RuntimeException('context correlation'); }, 0);
        $bus->listen(OrderCreated::class, static function(OrderCreated $event, EventContext $context) use (&$calls): void { $calls[]='priority:'.$event->id; }, 10);
        $bus->subscribe(new OrderSubscriber());
        $event = new OrderCreated(7);
        $context = new EventContext(str_repeat('a',16), (int)hrtime(true), 'corr-001');
        $bus->dispatchWithContext($event,$context);
        $assert($calls === ['priority:7','first:7'],'deterministic priority order');
        $assert(OrderSubscriber::$calls === ['high:7','normal:7'],'subscriber order');
        $bus->freeze();
        $threw=false; try { $bus->listen(OrderCreated::class, static function():void {}); } catch (LogicException) { $threw=true; }
        $assert($threw,'frozen registration must fail');
        $failed=false; $bus2=new EventDispatcher(); $bus2->listen(OrderCreated::class, static function():void { throw new RuntimeException('boom'); }); try { $bus2->dispatch(new OrderCreated(9)); } catch (EventDispatchException $e) { $failed=count($e->errors)===1 && $e->event instanceof OrderCreated; }
        $assert($failed,'listener failure must be wrapped');
        $this->addToAssertionCount(1);
    }
}
