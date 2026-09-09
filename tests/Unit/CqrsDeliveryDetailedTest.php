<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\CQRS\CommandBus;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\CQRS\CqrsEventResult;
use Zef\Framework\CQRS\CqrsHandlerConflictException;
use Zef\Framework\CQRS\CqrsHandlerNotFoundException;
use Zef\Framework\CQRS\CqrsMiddlewareInterface;
use Zef\Framework\CQRS\InMemoryIdempotencyStore;
use Zef\Framework\CQRS\QueryBus;
use Zef\Framework\Delivery\BoundedInMemoryIdempotencyStore;
use Zef\Framework\Delivery\DeliveryAttempt;
use Zef\Framework\Delivery\DeliveryMode;
use Zef\Framework\Delivery\DeliveryOperation;
use Zef\Framework\Delivery\DeliverySafety;
use Zef\Framework\Delivery\DeliveryState;
use Zef\Framework\Delivery\DeliveryStateMachine;
use Zef\Framework\Delivery\IdempotencyClaim;
use Zef\Framework\Delivery\RetryContext;
use Zef\Framework\Delivery\RetryDecision;
use Zef\Framework\Delivery\RetryDecisionReason;
use Zef\Framework\Delivery\RetryPolicy as DeliveryRetryPolicy;
use Zef\Framework\Event\EventDispatcher;
use Zef\Framework\Transport\RemoteTransportResult;
use Zef\Framework\Transport\TransportOutcome;

final class B2PlaceOrder
{
    public function __construct(public readonly int $orderId)
    {
    }
}

final class B2OrderPlaced
{
    public function __construct(public readonly int $orderId)
    {
    }
}

final class B2QueryOrder
{
    public function __construct(public readonly int $orderId)
    {
    }
}

interface B2MarkerInterface
{
}

interface B2MarkerInterface2
{
}

final class B2ImplementsMarker implements B2MarkerInterface
{
}

final class B2ImplementsBoth implements B2MarkerInterface, B2MarkerInterface2
{
}

final class CqrsDeliveryDetailedTest extends TestCase
{
    // ----- CQRS: CommandBus -----

    public function testCommandBusInvalidTtlAndMessageClass(): void
    {
        try {
            new CommandBus(null, 0);
            $this->fail('zero ttl');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $bus = new CommandBus();
        try {
            $bus->register('Zef\No\Such\Command', static fn (): mixed => null);
            $this->fail('invalid command class');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testCommandBusIdempotentDispatchByDefaultContext(): void
    {
        $store = new InMemoryIdempotencyStore();
        $executions = 0;
        $bus = new CommandBus($store, 3600);
        $bus->register(B2PlaceOrder::class, static function (B2PlaceOrder $command) use (&$executions): array {
            ++$executions;
            return ['orderId' => $command->orderId];
        });
        $bus->freeze();
        $this->assertTrue($bus->isFrozen());

        $resultA = $bus->dispatch(new B2PlaceOrder(1));
        $resultB = $bus->dispatch(new B2PlaceOrder(1));
        $this->assertSame(['orderId' => 1], $resultA);
        $this->assertSame($resultA, $resultB);
        $this->assertSame(2, $executions, 'no explicit key => no dedupe across dispatches');
    }

    public function testCommandBusIdempotencyKeyDedupesAcrossCommandClasses(): void
    {
        $store = new InMemoryIdempotencyStore();
        $countA = 0;
        $countB = 0;
        $bus = new CommandBus($store, 3600);
        $bus->register(B2PlaceOrder::class, static function () use (&$countA): array {
            ++$countA;
            return ['cmd' => 'a'];
        });
        $bus->register(B2QueryOrder::class, static function () use (&$countB): array {
            ++$countB;
            return ['cmd' => 'b'];
        });
        $bus->freeze();
        $ctx = new CqrsContext('corr-b2-001', null, 'idem-b2-001');

        $bus->dispatch(new B2PlaceOrder(1), $ctx);
        $bus->dispatch(new B2PlaceOrder(1), $ctx);
        $this->assertSame(1, $countA);

        $bus->dispatch(new B2QueryOrder(1), $ctx);
        $this->assertSame(1, $countB, 'dedupe key is command-class scoped');
    }

    public function testCommandBusDuplicateRegistrationThrowsConflict(): void
    {
        $bus = new CommandBus();
        $bus->register(B2PlaceOrder::class, static fn (): array => []);
        $this->expectException(CqrsHandlerConflictException::class);
        $bus->register(B2PlaceOrder::class, static fn (): array => []);
    }

    public function testCommandBusAmbiguousInterfaceResolutionThrows(): void
    {
        $bus = new CommandBus();
        $bus->register(B2MarkerInterface::class, static fn (): string => 'via-a');
        $bus->register(B2MarkerInterface2::class, static fn (): string => 'via-b');
        $bus->freeze();
        $this->expectException(CqrsHandlerConflictException::class);
        $bus->dispatch(new B2ImplementsBoth());
    }

    public function testCommandBusResolvesViaSingleInterfaceMatch(): void
    {
        $bus = new CommandBus();
        $bus->register(B2MarkerInterface::class, static fn (): string => 'via-interface');
        $bus->freeze();
        $this->assertSame('via-interface', $bus->dispatch(new B2ImplementsMarker(), new CqrsContext('corr-b2-013')));
    }

    public function testCommandBusEmitsEventsThroughEventBus(): void
    {
        $store = new InMemoryIdempotencyStore();
        $events = new EventDispatcher();
        $seen = [];
        $events->listen(B2OrderPlaced::class, static function (B2OrderPlaced $event) use (&$seen): void {
            $seen[] = $event->orderId;
        });
        $events->freeze();
        $bus = new CommandBus($store, 3600, $events);
        $bus->register(B2PlaceOrder::class, static fn (B2PlaceOrder $c): CqrsEventResult => new CqrsEventResult(['ok' => true], [new B2OrderPlaced($c->orderId)]));
        $bus->freeze();
        $result = $bus->dispatch(new B2PlaceOrder(99), new CqrsContext('corr-b2-002'));
        $this->assertSame(['ok' => true], $result);
        $this->assertSame([99], $seen);
    }

    public function testCommandBusWithoutEventBusIgnoresEvents(): void
    {
        $bus = new CommandBus();
        $bus->register(B2PlaceOrder::class, static fn (): CqrsEventResult => new CqrsEventResult(['ok' => true], [new B2OrderPlaced(1)]));
        $bus->freeze();
        $result = $bus->dispatch(new B2PlaceOrder(1), new CqrsContext('corr-b2-003'));
        $this->assertSame(['ok' => true], $result, 'no event bus => events silently dropped');
    }

    public function testCommandBusNoHandlerFound(): void
    {
        $bus = new CommandBus();
        $bus->freeze();
        $this->expectException(CqrsHandlerNotFoundException::class);
        $bus->dispatch(new B2PlaceOrder(1), new CqrsContext('corr-b2-004'));
    }

    public function testCommandBusMiddlewareShortCircuitAndOrder(): void
    {
        $log = [];
        $middleware = new class ($log) implements CqrsMiddlewareInterface {
            /** @param list<string> $log */
            public function __construct(private array &$log)
            {
            }

            #[Override]
            public function process(object $message, CqrsContext $context, Closure $next): mixed
            {
                $this->log[] = 'm1';
                return $next($message, $context);
            }

            /** @return list<string> */
            public function log(): array
            {
                return $this->log;
            }
        };
        $bus = new CommandBus();
        $bus->use($middleware);
        $bus->register(B2PlaceOrder::class, static function () use (&$log): string {
            $log[] = 'handler';
            return 'ok';
        });
        $bus->freeze();
        $this->assertSame('ok', $bus->dispatch(new B2PlaceOrder(1), new CqrsContext('corr-b2-005')));
        $this->assertSame(['m1', 'handler'], $middleware->log());
    }

    public function testQueryBusAmbiguousAndDuplicateAndMissing(): void
    {
        $q = new QueryBus();
        try {
            $q->register('Zef\No\Such\Query', static fn (): mixed => null);
            $this->fail('invalid query class');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $q->register(B2MarkerInterface::class, static fn (): string => 'a');
        $q->register(B2MarkerInterface2::class, static fn (): string => 'b');
        $q->freeze();
        $this->assertTrue($q->isFrozen());
        try {
            $q->ask(new B2ImplementsBoth(), new CqrsContext('corr-b2-006'));
            $this->fail('ambiguous query resolution must throw');
        } catch (CqrsHandlerConflictException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testQueryBusRegistrationAfterFreezeThrows(): void
    {
        $q = new QueryBus();
        $q->freeze();
        try {
            $q->register(B2QueryOrder::class, static fn (): array => []);
            $this->fail('registration after freeze');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        try {
            $q->use(new class implements CqrsMiddlewareInterface {
                #[Override]
                public function process(object $message, CqrsContext $context, Closure $next): mixed
                {
                    return $next($message, $context);
                }
            });
            $this->fail('use after freeze');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testQueryBusNoHandler(): void
    {
        $q = new QueryBus();
        $q->freeze();
        $this->expectException(CqrsHandlerNotFoundException::class);
        $q->ask(new B2QueryOrder(1), new CqrsContext('corr-b2-007'));
    }

    public function testCqrsContextValidationAndToEventContext(): void
    {
        try {
            new CqrsContext('bad');
            $this->fail('bad correlation');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new CqrsContext('corr-b2-008', 'bad-trace');
            $this->fail('bad traceparent');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new CqrsContext('corr-b2-009', null, 'bad-key');
            $this->fail('bad idempotency key');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new CqrsContext('corr-b2-010', null, null, ['' => 'x']);
            $this->fail('empty attribute key');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $context = new CqrsContext('corr-b2-011', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'idem-b2-011', ['k' => 'v']);
        $eventContext = $context->toEventContext();
        $this->assertSame('corr-b2-011', $eventContext->correlationId);
        $this->assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', $eventContext->traceParent);
        $this->assertSame(['k' => 'v'], $eventContext->attributes);
        $created = CqrsContext::create(null, 'idem-b2-012', ['k2' => 'v2']);
        $this->assertNotNull($created->idempotencyKey);
    }

    public function testInMemoryIdempotencyStoreDedupeAndEviction(): void
    {
        $store = new InMemoryIdempotencyStore(1);
        $calls = 0;
        $producer = static function () use (&$calls): string {
            ++$calls;
            return 'value';
        };
        $this->assertSame('value', $store->remember('key-000001', $producer, 3600));
        $this->assertSame('value', $store->remember('key-000001', $producer, 3600));
        $this->assertSame(1, $calls);
        $this->assertSame('other', $store->remember('key-000002', static fn (): string => 'other', 3600), 'evicts oldest when at capacity');

        try {
            $store->remember('bad', $producer);
            $this->fail('bad key');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $store->remember('key-000003', $producer, 0);
            $this->fail('zero ttl');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new InMemoryIdempotencyStore(0);
            $this->fail('capacity zero');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    // ----- Delivery: state machine + idempotency store -----

    public function testDeliveryStateMachineHappyPath(): void
    {
        $machine = new DeliveryStateMachine();
        $this->assertSame(DeliveryState::PREPARED, $machine->state());
        $machine->transition(DeliveryState::ATTEMPTING);
        $machine->transition(DeliveryState::INDETERMINATE);
        $machine->transition(DeliveryState::RETRY_SCHEDULED);
        $machine->transition(DeliveryState::ATTEMPTING);
        $machine->transition(DeliveryState::DEFINITIVE_SUCCESS);
        $this->assertSame(DeliveryState::DEFINITIVE_SUCCESS, $machine->state());
    }

    public function testDeliveryStateMachineIllegalTransition(): void
    {
        $machine = new DeliveryStateMachine();
        $this->expectException(\LogicException::class);
        $machine->transition(DeliveryState::DEFINITIVE_SUCCESS);
    }

    public function testDeliveryStateMachineTerminalHasNoOutgoingEdges(): void
    {
        $machine = new DeliveryStateMachine();
        $machine->transition(DeliveryState::ATTEMPTING);
        $machine->transition(DeliveryState::DEFINITIVE_FAILURE);
        $this->expectException(\LogicException::class);
        $machine->transition(DeliveryState::RECONCILING);
    }

    public function testDeliveryOperationValidation(): void
    {
        $valid = new DeliveryOperation('op-0001', 'create-order', DeliverySafety::SIDE_EFFECTING, DeliveryMode::IDEMPOTENT, 'idem-0001', 'fp-0001');
        $this->assertSame('create-order', $valid->operation);

        try {
            new DeliveryOperation('', 'op', DeliverySafety::SIDE_EFFECT_FREE, DeliveryMode::AT_MOST_ONCE);
            $this->fail('empty operationId');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new DeliveryOperation('op-0002', 'op', DeliverySafety::SIDE_EFFECTING, DeliveryMode::IDEMPOTENT);
            $this->fail('idempotent without key/fingerprint');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new DeliveryOperation('op-0003', 'op', DeliverySafety::SIDE_EFFECTING, DeliveryMode::RETRYABLE, 'key-without-fp');
            $this->fail('key without fingerprint');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testDeliveryAttemptAndRetryContextValidation(): void
    {
        $operation = new DeliveryOperation('op-0004', 'op', DeliverySafety::SIDE_EFFECT_FREE, DeliveryMode::AT_MOST_ONCE);
        $attempt = new DeliveryAttempt($operation, 1, 'attempt-0001');
        $this->assertSame(1, $attempt->attemptNumber);

        try {
            new DeliveryAttempt($operation, 0, 'attempt-0002');
            $this->fail('attempt zero');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new DeliveryAttempt($operation, 65, 'attempt-0003');
            $this->fail('attempt > 64');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new DeliveryAttempt($operation, 1, '');
            $this->fail('empty attemptId');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $ctx = new RetryContext(1_000, 0);
        $this->assertTrue($ctx->resourceAdmitted);
        $this->assertTrue($ctx->securityAllowed);
        try {
            new RetryContext(-1, 0);
            $this->fail('negative monotonic time');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new RetryContext(0, -1);
            $this->fail('negative cumulative delay');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testDeliveryRetryPolicyValidationAndBackoff(): void
    {
        try {
            new DeliveryRetryPolicy(0);
            $this->fail('maxAttempts zero');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new DeliveryRetryPolicy(65);
            $this->fail('maxAttempts > 64');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new DeliveryRetryPolicy(3, -1);
            $this->fail('negative initialDelayMs');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new DeliveryRetryPolicy(3, 0, -1);
            $this->fail('negative maxDelayMs');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new DeliveryRetryPolicy(3, 0, 0, -1);
            $this->fail('negative deadlineMs');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new DeliveryRetryPolicy(3, 0, 0, 0, 300_001);
            $this->fail('maxCumulativeDelayMs too large');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $policy = new DeliveryRetryPolicy(5, 100, 1_000);
        $this->assertSame(100, $policy->delayMs(2));
        $this->assertSame(200, $policy->delayMs(3));
        $this->assertSame(400, $policy->delayMs(4));
        $this->assertSame(1_000, $policy->delayMs(10), 'capped at maxDelayMs');
        $this->assertSame(0, (new DeliveryRetryPolicy(3, 0, 0))->delayMs(2), 'zero delays return 0');

        try {
            $policy->delayMs(1);
            $this->fail('nextAttemptNumber < 2');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testRetryDecisionValidation(): void
    {
        $decision = new RetryDecision(true, RetryDecisionReason::SAFE_TO_RETRY, 2, 100);
        $this->assertTrue($decision->allowed);
        try {
            new RetryDecision(true, RetryDecisionReason::SAFE_TO_RETRY, 0, 0);
            $this->fail('nextAttemptNumber zero');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new RetryDecision(true, RetryDecisionReason::SAFE_TO_RETRY, 1, -1);
    }

    public function testBoundedIdempotencyStoreFullLifecycle(): void
    {
        $store = new BoundedInMemoryIdempotencyStore(4);
        $this->assertFalse($store->supports(new DeliveryOperation('op-0005', 'op', DeliverySafety::SIDE_EFFECT_FREE, DeliveryMode::AT_MOST_ONCE)));
        $operation = new DeliveryOperation('op-0006', 'op', DeliverySafety::SIDE_EFFECTING, DeliveryMode::IDEMPOTENT, 'idem-key-001', 'fp-001');
        $this->assertTrue($store->supports($operation));

        $this->assertSame(IdempotencyClaim::NEW, $store->claim('idem-key-001', 'fp-001'));
        $this->assertSame(IdempotencyClaim::DUPLICATE, $store->claim('idem-key-001', 'fp-001'));
        $this->assertSame(IdempotencyClaim::CONFLICT, $store->claim('idem-key-001', 'fp-002'));
        $this->assertNull($store->completedResult('idem-key-001', 'fp-001'), 'in-flight record has no result yet');

        $result = new RemoteTransportResult(TransportOutcome::SUCCESS, '{"ok":true}', ['k' => 'v']);
        $store->complete('idem-key-001', 'fp-001', $result);
        $this->assertTrue($store->completedResult('idem-key-001', 'fp-001')?->isDefinitive());

        try {
            $store->complete('idem-key-001', 'fp-002', $result);
            $this->fail('conflicting fingerprint complete');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        try {
            $store->completedResult('idem-key-001', 'fp-002');
            $this->fail('conflicting fingerprint read');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }

        $store2 = new BoundedInMemoryIdempotencyStore(1);
        $store2->claim('idem-key-002', 'fp-a');
        try {
            $store2->claim('idem-key-003', 'fp-a');
            $this->fail('capacity exhausted');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testBoundedIdempotencyStoreValidation(): void
    {
        $store = new BoundedInMemoryIdempotencyStore();
        try {
            $store->claim('', 'fp');
            $this->fail('empty key');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $store->claim('key-ok', str_repeat('x', 129));
            $this->fail('fingerprint too long');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new BoundedInMemoryIdempotencyStore(0);
    }

    public function testRemoteTransportResultValidation(): void
    {
        $ok = new RemoteTransportResult(TransportOutcome::SUCCESS, 'payload', ['meta' => 1]);
        $this->assertTrue($ok->isDefinitive());
        $this->assertFalse((new RemoteTransportResult(TransportOutcome::INDETERMINATE))->isDefinitive());

        try {
            new RemoteTransportResult(TransportOutcome::SUCCESS, str_repeat('x', 1_048_577));
            $this->fail('payload > 1MiB');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new RemoteTransportResult(TransportOutcome::SUCCESS, null, ['k' => []]);
            $this->fail('non-scalar metadata value');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new RemoteTransportResult(TransportOutcome::SUCCESS, null, ['k' => str_repeat('x', 1025)]);
            $this->fail('string metadata value too long');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
