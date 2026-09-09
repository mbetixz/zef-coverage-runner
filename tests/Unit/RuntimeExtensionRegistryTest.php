<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Runtime\RuntimeExtensionContext;
use Zef\Framework\Runtime\RuntimeExtensionInterface;
use Zef\Framework\Runtime\RuntimeExtensionRegistry;
use Zef\Framework\Runtime\RuntimeExtensionState;
use Zef\Framework\Runtime\RuntimeIdentity;

final class RuntimeExtensionRegistryTest extends TestCase
{
    private RuntimeIdentity $identity;

    #[Override]
    protected function setUp(): void
    {
        $this->identity = new RuntimeIdentity('instance-1', 'worker-1', 1_700_000);
    }

    /**
     * @return array{RuntimeExtensionInterface, \ArrayObject<int, string>}
     */
    private function recorder(string $name, bool $failStart = false, bool $failStop = false): array
    {
        $events = new \ArrayObject();
        $extension = new class ($name, $failStart, $failStop, $events) implements RuntimeExtensionInterface {
            /** @param \ArrayObject<int, string> $events */
            public function __construct(
                private readonly string $name,
                private readonly bool $failStart,
                private readonly bool $failStop,
                private readonly \ArrayObject $events,
            ) {
            }

            #[\Override]
            public function name(): string
            {
                return $this->name;
            }

            #[\Override]
            public function start(RuntimeExtensionContext $context): void
            {
                if ($this->failStart) {
                    throw new \RuntimeException("start failed for {$this->name}");
                }
                $this->events->append("start:{$context->state->value}");
            }

            #[\Override]
            public function ready(RuntimeExtensionContext $context): void
            {
                $this->events->append("ready:{$context->state->value}");
            }

            #[\Override]
            public function draining(RuntimeExtensionContext $context): void
            {
                $this->events->append("draining:{$context->state->value}");
            }

            #[\Override]
            public function stop(RuntimeExtensionContext $context): void
            {
                if ($this->failStop) {
                    throw new \RuntimeException("stop failed for {$this->name}");
                }
                $this->events->append("stop:{$context->state->value}");
            }
        };

        return [$extension, $events];
    }

    public function testFullLifecycleInOrder(): void
    {
        [$a, $eventsA] = $this->recorder('ext.a');
        [$b, $eventsB] = $this->recorder('ext.b');
        $registry = new RuntimeExtensionRegistry();
        $registry->register($a);
        $registry->register($b);

        $this->assertSame(RuntimeExtensionState::STOPPED, $registry->state());
        $this->assertNull($registry->identity());
        $this->assertSame(['ext.a', 'ext.b'], $registry->registeredNames());

        $registry->start($this->identity);
        $this->assertSame(RuntimeExtensionState::STARTING, $registry->state());
        $this->assertSame($this->identity, $registry->identity());
        $this->assertSame(['start:starting'], $eventsA->getArrayCopy());
        $this->assertSame(['start:starting'], $eventsB->getArrayCopy());

        $registry->ready();
        $this->assertSame(RuntimeExtensionState::READY, $registry->state());
        $this->assertSame(['start:starting', 'ready:ready'], $eventsA->getArrayCopy());

        $registry->draining();
        $this->assertSame(RuntimeExtensionState::DRAINING, $registry->state());
        // draining notifies every started extension, in order.
        $this->assertSame(['start:starting', 'ready:ready', 'draining:draining'], $eventsA->getArrayCopy());
        $this->assertSame(['start:starting', 'ready:ready', 'draining:draining'], $eventsB->getArrayCopy());

        $registry->stop();
        $this->assertSame(RuntimeExtensionState::STOPPED, $registry->state());
        $this->assertSame(['start:starting', 'ready:ready', 'draining:draining', 'stop:stopped'], $eventsA->getArrayCopy());
        $this->assertSame(['start:starting', 'ready:ready', 'draining:draining', 'stop:stopped'], $eventsB->getArrayCopy());
    }

    public function testRegisterAfterStartThrows(): void
    {
        [$a] = $this->recorder('ext.a');
        $registry = new RuntimeExtensionRegistry();
        $registry->register($a);
        $registry->start($this->identity);

        [$late] = $this->recorder('ext.late');
        $this->expectException(\LogicException::class);
        $registry->register($late);
    }

    public function testDuplicateRegistrationThrows(): void
    {
        [$a] = $this->recorder('ext.dup');
        [$b] = $this->recorder('ext.dup');
        $registry = new RuntimeExtensionRegistry();
        $registry->register($a);

        $this->expectException(\InvalidArgumentException::class);
        $registry->register($b);
    }

    public function testEmptyNameRegistrationThrows(): void
    {
        [$blank] = $this->recorder('   ');
        $registry = new RuntimeExtensionRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $registry->register($blank);
    }

    public function testReadyBeforeStartThrows(): void
    {
        $registry = new RuntimeExtensionRegistry();

        $this->expectException(\LogicException::class);
        $registry->ready();
    }

    public function testDrainingBeforeStartThrows(): void
    {
        $registry = new RuntimeExtensionRegistry();

        $this->expectException(\LogicException::class);
        $registry->draining();
    }

    public function testIllegalReadyFromReadyThrows(): void
    {
        [$a] = $this->recorder('ext.a');
        $registry = new RuntimeExtensionRegistry();
        $registry->register($a);
        $registry->start($this->identity);
        $registry->ready();

        $this->expectException(\LogicException::class);
        $registry->ready();
    }

    public function testStartFailureRollsBackStartedExtensions(): void
    {
        [$a, $eventsA] = $this->recorder('ext.a');
        [$b] = $this->recorder('ext.b', failStart: true);
        $registry = new RuntimeExtensionRegistry();
        $registry->register($a);
        $registry->register($b);

        try {
            $registry->start($this->identity);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('start failed for ext.b', $e->getMessage());
        }

        $this->assertSame(RuntimeExtensionState::STOPPED, $registry->state());
        $this->assertSame(['start:starting', 'stop:stopped'], $eventsA->getArrayCopy(), 'started extension must be rolled back');
    }

    public function testStopWithoutStartIsNoop(): void
    {
        $registry = new RuntimeExtensionRegistry();
        $registry->stop();

        $this->assertSame(RuntimeExtensionState::STOPPED, $registry->state());
    }

    public function testStopCollectsExtensionErrors(): void
    {
        [$a] = $this->recorder('ext.a');
        [$failing] = $this->recorder('ext.failing', failStop: true);
        $registry = new RuntimeExtensionRegistry();
        $registry->register($a);
        $registry->register($failing);
        $registry->start($this->identity);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('One or more runtime extensions failed during stop.');
        $registry->stop();
    }

    public function testStopTwiceIsIdempotent(): void
    {
        [$a] = $this->recorder('ext.a');
        $registry = new RuntimeExtensionRegistry();
        $registry->register($a);
        $registry->start($this->identity);
        $registry->stop();
        $registry->stop();

        $this->assertSame(RuntimeExtensionState::STOPPED, $registry->state());
    }

    public function testStartTwiceThrows(): void
    {
        [$a] = $this->recorder('ext.a');
        $registry = new RuntimeExtensionRegistry();
        $registry->register($a);
        $registry->start($this->identity);

        $this->expectException(\LogicException::class);
        $registry->start($this->identity);
    }
}
