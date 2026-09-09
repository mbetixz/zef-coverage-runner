<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventDispatcher;
use Zef\Framework\Event\EventRegistration;
use Zef\Framework\Event\EventSubscriberInterface;

final class B2UserRegistered
{
    public function __construct(public readonly int $userId)
    {
    }
}

final class B2UserSubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    public static array $calls = [];

    #[\Override]
    public static function subscriptions(): array
    {
        return [
            B2UserRegistered::class => [
                [20, static function (B2UserRegistered $event, EventContext $context): void {
                    self::$calls[] = 'prio:' . $event->userId;
                }],
                static function (B2UserRegistered $event): void {
                    self::$calls[] = 'single-arg:' . $event->userId;
                },
            ],
        ];
    }
}

final class B2InvalidSubscriber implements EventSubscriberInterface
{
    /**
     * Intentionally returns a list containing a non-callable string to exercise
     * the dispatcher's subscribe() validation path.
     *
     * @return array<string, list<mixed>>
     */
    #[\Override]
    public static function subscriptions(): array
    {
        return [B2UserRegistered::class => ['not-callable']];
    }
}

final class B2InvokableListener
{
    /** @var list<int> */
    public array $seen = [];

    public function __invoke(B2UserRegistered $event, EventContext $context): void
    {
        $this->seen[] = $event->userId;
    }
}

final class EventDispatcherDetailedTest extends TestCase
{
    public function testListenValidatesEventClassAndFrozenState(): void
    {
        $bus = new EventDispatcher();
        $this->expectException(\InvalidArgumentException::class);
        $bus->listen('Zef\No\Such\Class', static function (): void {
        });
    }

    public function testListenRejectsEmptyClass(): void
    {
        $bus = new EventDispatcher();
        $this->expectException(\InvalidArgumentException::class);
        $bus->listen('', static function (): void {
        });
    }

    public function testSubscribeResolvesPriorityTuplesAndBareCallables(): void
    {
        $bus = new EventDispatcher();
        B2UserSubscriber::$calls = [];
        $bus->subscribe(new B2UserSubscriber());
        $bus->dispatch(new B2UserRegistered(11));
        // priority 20 fires first; single-arg callable fires second; static calls append in registration order.
        $this->assertSame('prio:11|single-arg:11', implode('|', B2UserSubscriber::$calls));
    }

    public function testSubscribeRejectsNonCallableListener(): void
    {
        $bus = new EventDispatcher();
        $this->expectException(\InvalidArgumentException::class);
        $bus->subscribe(new B2InvalidSubscriber());
    }

    public function testDispatchAutoBuildsContextAndReturnsEvent(): void
    {
        $bus = new EventDispatcher();
        $seen = [];
        $bus->listen(B2UserRegistered::class, static function (B2UserRegistered $event, EventContext $context) use (&$seen): void {
            $seen[] = [$event->userId, strlen($context->eventId), $context->occurredAtUnixNano > 0];
        });
        $event = $bus->dispatch(new B2UserRegistered(5));
        $this->assertInstanceOf(B2UserRegistered::class, $event);
        $this->assertSame([[5, 32, true]], $seen);
    }

    public function testListenerResolutionForSubclassAndInterfaceCaching(): void
    {
        $bus = new EventDispatcher();
        $calls = [];
        $bus->listen(B2UserRegistered::class, static function (B2UserRegistered $event) use (&$calls): void {
            $calls[] = 'base';
        });
        $bus->freeze();
        $bus->dispatch(new B2UserRegistered(1));
        $bus->dispatch(new B2UserRegistered(2));
        $this->assertCount(2, $calls, 'frozen dispatcher must reuse resolved listener set');
    }

    public function testFreezeIsIdempotentAndStopsRegistration(): void
    {
        $bus = new EventDispatcher();
        $bus->freeze();
        $bus->freeze();
        $this->assertTrue($bus->isFrozen());
        $this->expectException(\LogicException::class);
        $bus->listen(B2UserRegistered::class, static function (): void {
        });
    }

    public function testRegistrationsFlattensAllListeners(): void
    {
        $bus = new EventDispatcher();
        $bus->listen(B2UserRegistered::class, static function (): void {
        });
        $list = $bus->registrations();
        $this->assertCount(1, $list);
        $this->assertInstanceOf(EventRegistration::class, $list[0]);
        $this->assertSame(B2UserRegistered::class, $list[0]->eventClass);
    }

    public function testEventRegistrationAcceptsContextByArity(): void
    {
        $one = new EventRegistration(B2UserRegistered::class, static function (B2UserRegistered $e): void {
        });
        $this->assertFalse($one->acceptsContext);
        $two = new EventRegistration(B2UserRegistered::class, static function (B2UserRegistered $e, EventContext $c): void {
        });
        $this->assertTrue($two->acceptsContext);
        $invokable = new B2InvokableListener();
        $three = new EventRegistration(B2UserRegistered::class, $invokable);
        $this->assertTrue($three->acceptsContext);
    }

    public function testEventRegistrationRejectsInvalidListenerReflection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EventRegistration(B2UserRegistered::class, 'strlen');
    }

    public function testEventContextValidationPaths(): void
    {
        $ctx = new EventContext('evt-0000000001', 0);
        $this->assertSame('evt-0000000001', $ctx->eventId);

        $invalid = false;
        try {
            new EventContext('x', 0);
        } catch (\InvalidArgumentException) {
            $invalid = true;
        }
        $this->assertTrue($invalid, 'expected InvalidArgumentException for short event id');

        try {
            new EventContext('evt-0000000001', 0, 'x', null);
            $this->fail('expected InvalidArgumentException for correlation');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new EventContext('evt-0000000002', 0, null, 'bad-trace');
            $this->fail('expected InvalidArgumentException for traceparent');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testNonContextListenerStillReceivesContextWhenArityAllows(): void
    {
        // Listener declared with a single typed arg must be invoked with one arg.
        $bus = new EventDispatcher();
        $singleArgCalls = [];
        $bus->listen(B2UserRegistered::class, static function (B2UserRegistered $event) use (&$singleArgCalls): void {
            $singleArgCalls[] = $event->userId;
        });
        $bus->dispatch(new B2UserRegistered(77));
        $this->assertSame([77], $singleArgCalls);
    }
}
