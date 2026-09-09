<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Runtime\RuntimeExtensionContext;
use Zef\Framework\Runtime\RuntimeExtensionInterface;
use Zef\Framework\Runtime\RuntimeExtensionRegistry;
use Zef\Framework\Runtime\RuntimeExtensionState;
use Zef\Framework\Runtime\RuntimeIdentity;

final class G4_8RuntimeExtensionContractTest extends TestCase
{
    public function testLifecycleIsOrderedAndUsesImmutableExplicitContext(): void
    {
        $events = new TestEventLog();
        $extension = new TestRuntimeExtension('demo', $events);
        $registry = new RuntimeExtensionRegistry();
        $registry->register($extension);
        $identity = new RuntimeIdentity('instance-1', 'worker-1', 123);

        $registry->start($identity);
        $registry->ready();
        $registry->draining();
        $registry->stop();

        self::assertSame(['start', 'ready', 'draining', 'stop'], $events->events);
        self::assertSame(RuntimeExtensionState::STOPPED, $registry->state());
        self::assertSame($identity, $registry->identity());
        self::assertSame('instance-1', $extension->contexts[0]->identity->instanceId);
    }

    public function testRegistrationIsFrozenAfterStart(): void
    {
        $registry = new RuntimeExtensionRegistry();
        $events = new TestEventLog();
        $registry->register(new TestRuntimeExtension('one', $events));
        $registry->start(new RuntimeIdentity('i', 'w', 1));

        $this->expectException(LogicException::class);
        $events2 = new TestEventLog();
        $registry->register(new TestRuntimeExtension('two', $events2));
    }

    public function testDuplicateNamesAreRejected(): void
    {
        $registry = new RuntimeExtensionRegistry();
        $events1 = new TestEventLog();
        $registry->register(new TestRuntimeExtension('same', $events1));

        $this->expectException(InvalidArgumentException::class);
        $events2 = new TestEventLog();
        $registry->register(new TestRuntimeExtension('same', $events2));
    }

    public function testStartupFailureRollsBackStartedExtensions(): void
    {
        $events = new TestEventLog();
        $first = new TestRuntimeExtension('first', $events);
        $second = new TestRuntimeExtension('second', $events, failOn: 'start');
        $registry = new RuntimeExtensionRegistry();
        $registry->register($first);
        $registry->register($second);

        $this->expectException(RuntimeException::class);
        try {
            $registry->start(new RuntimeIdentity('i', 'w', 2));
        } finally {
            self::assertSame(['start', 'start', 'stop'], $events->events);
            self::assertSame(RuntimeExtensionState::STOPPED, $registry->state());
        }
    }

    public function testIllegalReadyAfterDrainingIsRejected(): void
    {
        $registry = new RuntimeExtensionRegistry();
        $events = new TestEventLog();
        $registry->register(new TestRuntimeExtension('one', $events));
        $registry->start(new RuntimeIdentity('i', 'w', 3));
        $registry->draining();

        $this->expectException(LogicException::class);
        $registry->ready();
    }

    public function testContextRejectsNonScalarAttributes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @var array<string, mixed> $attributes */
        $attributes = ['bad' => new stdClass()];
        new RuntimeExtensionContext(
            new RuntimeIdentity('i', 'w', 1),
            RuntimeExtensionState::STARTING,
            $attributes,
        );
    }
}

final class TestEventLog
{
    /** @var list<string> */
    public array $events = [];
}

final class TestRuntimeExtension implements RuntimeExtensionInterface
{
    /** @var list<RuntimeExtensionContext> */
    public array $contexts = [];

    public function __construct(
        private readonly string $id,
        private readonly TestEventLog $log,
        private readonly ?string $failOn = null,
    ) {}

    #[\Override]
    public function name(): string { return $this->id; }

    #[\Override]
    public function start(RuntimeExtensionContext $context): void
    {
        $this->log->events[] = 'start';
        $this->contexts[] = $context;
        if ($this->failOn === 'start') throw new RuntimeException('planned startup failure');
    }

    #[\Override]
    public function ready(RuntimeExtensionContext $context): void
    {
        $this->log->events[] = 'ready';
        $this->contexts[] = $context;
    }

    #[\Override]
    public function draining(RuntimeExtensionContext $context): void
    {
        $this->log->events[] = 'draining';
        $this->contexts[] = $context;
    }

    #[\Override]
    public function stop(RuntimeExtensionContext $context): void
    {
        $this->log->events[] = 'stop';
        $this->contexts[] = $context;
    }
}
