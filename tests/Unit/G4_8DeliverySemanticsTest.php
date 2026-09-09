<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Delivery\BoundedInMemoryIdempotencyStore;
use Zef\Framework\Delivery\DeliveryAttempt;
use Zef\Framework\Delivery\DeliverySafety;
use Zef\Framework\Delivery\DeliveryState;
use Zef\Framework\Delivery\DeliveryStateMachine;
use Zef\Framework\Delivery\DeliveryMode;
use Zef\Framework\Delivery\DeliveryObservation;
use Zef\Framework\Delivery\DeliveryOperation;
use Zef\Framework\Delivery\DeliverySemanticsEvaluator;
use Zef\Framework\Delivery\ExecutionCertainty;
use Zef\Framework\Delivery\IdempotencyClaim;
use Zef\Framework\Delivery\RetryContext;
use Zef\Framework\Delivery\RetryDecisionReason;
use Zef\Framework\Delivery\RetryPolicy;
use Zef\Framework\Transport\RemoteTransportResult;
use Zef\Framework\Transport\TransportOutcome;

final class G4_8DeliverySemanticsTest extends TestCase
{
    private function operation(DeliveryMode $mode = DeliveryMode::RETRYABLE): DeliveryOperation
    {
        return new DeliveryOperation(
            'op-001',
            'orders.charge',
            DeliverySafety::SIDE_EFFECTING,
            $mode,
            'idem-001',
            'fp-001',
        );
    }

    private function makeAttempt(DeliveryOperation $operation, int $number = 1): DeliveryAttempt
    {
        return new DeliveryAttempt($operation, $number, 'attempt-'.$number);
    }

    public function testOperationAndAttemptIdentitiesAreDistinctAndBounded(): void
    {
        $operation = $this->operation();
        $attempt = $this->makeAttempt($operation, 2);

        self::assertSame('op-001', $operation->operationId);
        self::assertSame('attempt-2', $attempt->attemptId);
        self::assertSame(2, $attempt->attemptNumber);
        self::assertNotSame($operation->operationId, $attempt->attemptId);
    }

    public function testIndeterminateNeverRetriesWithoutExplicitGuarantee(): void
    {
        $operation = new DeliveryOperation('op-002', 'orders.charge', DeliverySafety::SIDE_EFFECTING, DeliveryMode::RETRYABLE);
        $evaluator = new DeliverySemanticsEvaluator();
        $decision = $evaluator->shouldRetry(
            $this->makeAttempt($operation),
            new RemoteTransportResult(TransportOutcome::TIMEOUT),
            new RetryPolicy(3, 10, 100, 1000, 1000),
            new RetryContext(10, 0),
        );

        self::assertSame(ExecutionCertainty::INDETERMINATE, $evaluator->executionCertainty(new RemoteTransportResult(TransportOutcome::TIMEOUT)));
        self::assertFalse($decision->allowed);
        self::assertSame(RetryDecisionReason::IDEMPOTENCY_GUARANTEE_REQUIRED, $decision->reason);
    }

    public function testIndeterminateRetriesOnlyWithGuarantee(): void
    {
        $store = new BoundedInMemoryIdempotencyStore();
        $operation = $this->operation(DeliveryMode::IDEMPOTENT);
        $decision = (new DeliverySemanticsEvaluator($store))->shouldRetry(
            $this->makeAttempt($operation),
            new RemoteTransportResult(TransportOutcome::INDETERMINATE),
            new RetryPolicy(3, 10, 100, 1000, 1000),
            new RetryContext(10, 0),
        );

        self::assertTrue($decision->allowed);
        self::assertSame(RetryDecisionReason::SAFE_TO_RETRY, $decision->reason);
        self::assertSame(2, $decision->nextAttemptNumber);
        self::assertSame(10, $decision->delayMs);
    }

    public function testDefinitivelyExecutedFailureIsNeverRetried(): void
    {
        $store = new BoundedInMemoryIdempotencyStore();
        $decision = (new DeliverySemanticsEvaluator($store))->shouldRetry(
            $this->makeAttempt($this->operation()),
            new RemoteTransportResult(TransportOutcome::REMOTE_PROCESSING_FAILURE),
            new RetryPolicy(5),
            new RetryContext(0, 0),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(RetryDecisionReason::DEFINITIVELY_EXECUTED, $decision->reason);
    }

    public function testAtMostOnceNeverRetries(): void
    {
        $operation = new DeliveryOperation('op-003', 'orders.charge', DeliverySafety::SIDE_EFFECTING, DeliveryMode::AT_MOST_ONCE);
        $decision = (new DeliverySemanticsEvaluator(new BoundedInMemoryIdempotencyStore()))->shouldRetry(
            $this->makeAttempt($operation),
            new RemoteTransportResult(TransportOutcome::TIMEOUT),
            new RetryPolicy(5),
            new RetryContext(0, 0),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(RetryDecisionReason::AT_MOST_ONCE, $decision->reason);
    }

    public function testRetryRequiresSecurityAndResourceAdmission(): void
    {
        $evaluator = new DeliverySemanticsEvaluator(new BoundedInMemoryIdempotencyStore());
        $attempt = $this->makeAttempt($this->operation());
        $result = new RemoteTransportResult(TransportOutcome::UNAVAILABLE);
        $policy = new RetryPolicy(5);

        self::assertSame(RetryDecisionReason::RESOURCE_DENIED, $evaluator->shouldRetry($attempt, $result, $policy, new RetryContext(0, 0, false, true))->reason);
        self::assertSame(RetryDecisionReason::SECURITY_DENIED, $evaluator->shouldRetry($attempt, $result, $policy, new RetryContext(0, 0, true, false))->reason);
    }

    public function testRetryBudgetAndDeadlineAreHardBounds(): void
    {
        $evaluator = new DeliverySemanticsEvaluator(new BoundedInMemoryIdempotencyStore());
        $attempt = $this->makeAttempt($this->operation());
        $result = new RemoteTransportResult(TransportOutcome::TRANSPORT_FAILURE);

        self::assertSame(
            RetryDecisionReason::RETRY_BUDGET_EXHAUSTED,
            $evaluator->shouldRetry($attempt, $result, new RetryPolicy(5, 20, 100, 1000, 10), new RetryContext(0, 0))->reason,
        );
        self::assertSame(
            RetryDecisionReason::DEADLINE_EXPIRED,
            $evaluator->shouldRetry($attempt, $result, new RetryPolicy(5, 20, 100, 15, 100), new RetryContext(0, 0))->reason,
        );
    }

    public function testAttemptLimitIsEnforced(): void
    {
        $evaluator = new DeliverySemanticsEvaluator(new BoundedInMemoryIdempotencyStore());
        $decision = $evaluator->shouldRetry(
            $this->makeAttempt($this->operation(), 3),
            new RemoteTransportResult(TransportOutcome::UNAVAILABLE),
            new RetryPolicy(3),
            new RetryContext(0, 0),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(RetryDecisionReason::ATTEMPT_LIMIT_REACHED, $decision->reason);
    }

    public function testIdempotencyStorePreventsConcurrentDuplicateClaimAndDetectsConflict(): void
    {
        $store = new BoundedInMemoryIdempotencyStore(2);
        self::assertSame(IdempotencyClaim::NEW, $store->claim('key-1', 'fp-a'));
        self::assertSame(IdempotencyClaim::DUPLICATE, $store->claim('key-1', 'fp-a'));
        self::assertSame(IdempotencyClaim::CONFLICT, $store->claim('key-1', 'fp-b'));
    }

    public function testIdempotencyStoreRetainsCompletedResult(): void
    {
        $store = new BoundedInMemoryIdempotencyStore();
        $result = new RemoteTransportResult(TransportOutcome::SUCCESS, 'ok');
        self::assertSame(IdempotencyClaim::NEW, $store->claim('key-2', 'fp-c'));
        $store->complete('key-2', 'fp-c', $result);

        self::assertSame('ok', $store->completedResult('key-2', 'fp-c')?->payload);
    }

    public function testIdempotencyCapacityIsBounded(): void
    {
        $store = new BoundedInMemoryIdempotencyStore(1);
        self::assertSame(IdempotencyClaim::NEW, $store->claim('key-a', 'fp-a'));
        $this->expectException(RuntimeException::class);
        $store->claim('key-b', 'fp-b');
    }

    public function testBackoffIsDeterministicAndCapped(): void
    {
        $policy = new RetryPolicy(6, 10, 25, 0, 1000);
        self::assertSame(10, $policy->delayMs(2));
        self::assertSame(20, $policy->delayMs(3));
        self::assertSame(25, $policy->delayMs(4));
        self::assertSame(25, $policy->delayMs(6));
    }

    public function testSideEffectFreeRetryDoesNotRequireIdempotencyGuarantee(): void
    {
        $operation = new DeliveryOperation('op-005', 'catalog.lookup', DeliverySafety::SIDE_EFFECT_FREE, DeliveryMode::RETRYABLE);
        $decision = (new DeliverySemanticsEvaluator())->shouldRetry(
            $this->makeAttempt($operation),
            new RemoteTransportResult(TransportOutcome::TIMEOUT),
            new RetryPolicy(3, 5, 20, 100, 100),
            new RetryContext(10, 0),
        );

        self::assertTrue($decision->allowed);
        self::assertSame(RetryDecisionReason::SAFE_TO_RETRY, $decision->reason);
    }

    public function testExplicitObservationCanMarkTimeoutAsDefinitelyNotExecuted(): void
    {
        $operation = new DeliveryOperation('op-006', 'catalog.lookup', DeliverySafety::SIDE_EFFECTING, DeliveryMode::RETRYABLE);
        $observation = new DeliveryObservation(
            new RemoteTransportResult(TransportOutcome::TIMEOUT),
            ExecutionCertainty::DEFINITELY_NOT_EXECUTED,
        );
        $decision = (new DeliverySemanticsEvaluator())->shouldRetryObservation(
            $this->makeAttempt($operation),
            $observation,
            new RetryPolicy(3, 5, 20, 100, 100),
            new RetryContext(10, 0),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(RetryDecisionReason::IDEMPOTENCY_GUARANTEE_REQUIRED, $decision->reason);
    }

    public function testStateMachineRejectsImplicitIndeterminateToRetryTransition(): void
    {
        $machine = new DeliveryStateMachine();
        $machine->transition(DeliveryState::ATTEMPTING);
        $machine->transition(DeliveryState::INDETERMINATE);

        $this->expectException(LogicException::class);
        $machine->transition(DeliveryState::ATTEMPTING);
    }

    public function testStateMachineRequiresExplicitRetryScheduledEdge(): void
    {
        $machine = new DeliveryStateMachine();
        $machine->transition(DeliveryState::ATTEMPTING);
        $machine->transition(DeliveryState::INDETERMINATE);
        $machine->transition(DeliveryState::RETRY_SCHEDULED);
        $machine->transition(DeliveryState::ATTEMPTING);

        self::assertSame(DeliveryState::ATTEMPTING, $machine->state());
    }

    public function testIdempotentModeRequiresIdentityMaterial(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DeliveryOperation('op-004', 'orders.charge', DeliverySafety::SIDE_EFFECTING, DeliveryMode::IDEMPOTENT);
    }
}
