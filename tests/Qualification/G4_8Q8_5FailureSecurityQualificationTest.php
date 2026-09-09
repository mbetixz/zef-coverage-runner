<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Delivery\BoundedInMemoryIdempotencyStore;
use Zef\Framework\Delivery\DeliveryAttempt;
use Zef\Framework\Delivery\DeliveryMode;
use Zef\Framework\Delivery\DeliveryObservation;
use Zef\Framework\Delivery\DeliveryOperation;
use Zef\Framework\Delivery\DeliverySafety;
use Zef\Framework\Delivery\DeliverySemanticsEvaluator;
use Zef\Framework\Delivery\DeliveryState;
use Zef\Framework\Delivery\DeliveryStateMachine;
use Zef\Framework\Delivery\ExecutionCertainty;
use Zef\Framework\Delivery\IdempotencyClaim;
use Zef\Framework\Delivery\RetryContext;
use Zef\Framework\Delivery\RetryDecisionReason;
use Zef\Framework\Delivery\RetryPolicy;
use Zef\Framework\Resource\InMemoryAdmissionController;
use Zef\Framework\Resource\ResourceBudget;
use Zef\Framework\Security\Distributed\AuthenticationResult;
use Zef\Framework\Security\Distributed\AuthenticationStatus;
use Zef\Framework\Security\Distributed\AuthorizationResult;
use Zef\Framework\Security\Distributed\BoundedInMemoryReplayProtector;
use Zef\Framework\Security\Distributed\DefaultSecurityBoundary;
use Zef\Framework\Security\Distributed\ReplayDecision;
use Zef\Framework\Security\Distributed\SecurityContext;
use Zef\Framework\Security\Distributed\SecurityFailure;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\Distributed\SecurityVerdict;
use Zef\Framework\Transport\RemoteTransportResult;
use Zef\Framework\Transport\TransportOutcome;

final class G4_8Q8_5FailureSecurityQualificationTest extends TestCase
{
    private function transportResult(TransportOutcome $outcome): RemoteTransportResult
    {
        return new RemoteTransportResult($outcome, null, []);
    }

    private function makeDeliveryAttempt(
        DeliverySafety $safety = DeliverySafety::SIDE_EFFECTING,
        DeliveryMode $mode = DeliveryMode::RETRYABLE,
        ?string $idempotencyKey = null,
        ?string $fingerprint = null,
        int $number = 1,
    ): DeliveryAttempt {
        return new DeliveryAttempt(
            new DeliveryOperation('operation-1', 'mutation', $safety, $mode, $idempotencyKey, $fingerprint),
            $number,
            'attempt-' . $number,
        );
    }

    public function testIndeterminateSideEffectingFailsClosedWithoutGuarantee(): void
    {
        $evaluator = new DeliverySemanticsEvaluator();
        $decision = $evaluator->shouldRetry(
            $this->makeDeliveryAttempt(),
            $this->transportResult(TransportOutcome::TIMEOUT),
            new RetryPolicy(5, 10, 100, 0, 100),
            new RetryContext(10, 0),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(RetryDecisionReason::IDEMPOTENCY_GUARANTEE_REQUIRED, $decision->reason);
        self::assertSame(ExecutionCertainty::INDETERMINATE, $evaluator->executionCertainty($this->transportResult(TransportOutcome::TIMEOUT)));
    }

    public function testIndeterminateWithExplicitIdempotencyGuaranteeMayRetryWithinBounds(): void
    {
        $store = new BoundedInMemoryIdempotencyStore();
        $operation = new DeliveryOperation('operation-1', 'mutation', DeliverySafety::SIDE_EFFECTING, DeliveryMode::IDEMPOTENT, 'idem-1', 'fp-1');
        self::assertTrue($store->supports($operation));
        self::assertSame(IdempotencyClaim::NEW, $store->claim('idem-1', 'fp-1'));

        $decision = (new DeliverySemanticsEvaluator($store))->shouldRetry(
            new DeliveryAttempt($operation, 1, 'attempt-1'),
            $this->transportResult(TransportOutcome::INDETERMINATE),
            new RetryPolicy(3, 10, 50, 1000, 100),
            new RetryContext(100, 0, true, true),
        );

        self::assertTrue($decision->allowed);
        self::assertSame(RetryDecisionReason::SAFE_TO_RETRY, $decision->reason);
        self::assertSame(10, $decision->delayMs);
    }

    public function testDefinitivelyExecutedNeverRetries(): void
    {
        $evaluator = new DeliverySemanticsEvaluator(new BoundedInMemoryIdempotencyStore());
        $operation = new DeliveryOperation('operation-1', 'mutation', DeliverySafety::SIDE_EFFECTING, DeliveryMode::IDEMPOTENT, 'idem-1', 'fp-1');
        $decision = $evaluator->shouldRetryObservation(
            new DeliveryAttempt($operation, 1, 'attempt-1'),
            new DeliveryObservation($this->transportResult(TransportOutcome::TRANSPORT_FAILURE), ExecutionCertainty::DEFINITELY_EXECUTED),
            new RetryPolicy(5),
            new RetryContext(0, 0),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(RetryDecisionReason::DEFINITIVELY_EXECUTED, $decision->reason);
    }

    public function testSecurityDeniedRetryContextNeverAllowsRetry(): void
    {
        $operation = new DeliveryOperation('operation-1', 'mutation', DeliverySafety::SIDE_EFFECTING, DeliveryMode::IDEMPOTENT, 'idem-1', 'fp-1');
        $decision = (new DeliverySemanticsEvaluator(new BoundedInMemoryIdempotencyStore()))->shouldRetry(
            new DeliveryAttempt($operation, 1, 'attempt-1'),
            $this->transportResult(TransportOutcome::UNAVAILABLE),
            new RetryPolicy(5),
            new RetryContext(0, 0, true, false),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(RetryDecisionReason::SECURITY_DENIED, $decision->reason);
    }

    public function testResourceDeniedRetryContextNeverAllowsRetry(): void
    {
        $operation = new DeliveryOperation('operation-1', 'mutation', DeliverySafety::SIDE_EFFECTING, DeliveryMode::IDEMPOTENT, 'idem-1', 'fp-1');
        $decision = (new DeliverySemanticsEvaluator(new BoundedInMemoryIdempotencyStore()))->shouldRetry(
            new DeliveryAttempt($operation, 1, 'attempt-1'),
            $this->transportResult(TransportOutcome::UNAVAILABLE),
            new RetryPolicy(5),
            new RetryContext(0, 0, false, true),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(RetryDecisionReason::RESOURCE_DENIED, $decision->reason);
    }

    public function testAttemptLimitAndRetryBudgetAndDeadlineFailClosed(): void
    {
        $evaluator = new DeliverySemanticsEvaluator(new BoundedInMemoryIdempotencyStore());
        $operation = new DeliveryOperation('operation-1', 'mutation', DeliverySafety::SIDE_EFFECTING, DeliveryMode::IDEMPOTENT, 'idem-1', 'fp-1');
        $attempt = new DeliveryAttempt($operation, 2, 'attempt-2');

        $attemptLimit = $evaluator->shouldRetry($attempt, $this->transportResult(TransportOutcome::TIMEOUT), new RetryPolicy(2), new RetryContext(0, 0));
        self::assertSame(RetryDecisionReason::ATTEMPT_LIMIT_REACHED, $attemptLimit->reason);

        $budget = $evaluator->shouldRetry(new DeliveryAttempt($operation, 1, 'attempt-1'), $this->transportResult(TransportOutcome::TIMEOUT), new RetryPolicy(3, 50, 50, 0, 25), new RetryContext(0, 0));
        self::assertSame(RetryDecisionReason::RETRY_BUDGET_EXHAUSTED, $budget->reason);

        $deadline = $evaluator->shouldRetry(new DeliveryAttempt($operation, 1, 'attempt-1'), $this->transportResult(TransportOutcome::TIMEOUT), new RetryPolicy(3, 50, 50, 100), new RetryContext(99, 0));
        self::assertSame(RetryDecisionReason::DEADLINE_EXPIRED, $deadline->reason);
    }

    public function testAtMostOnceAndSecurityTransportFailuresNeverRetry(): void
    {
        $atMostOnce = (new DeliverySemanticsEvaluator())->shouldRetry(
            $this->makeDeliveryAttempt(DeliverySafety::SIDE_EFFECT_FREE, DeliveryMode::AT_MOST_ONCE),
            $this->transportResult(TransportOutcome::TIMEOUT),
            new RetryPolicy(5),
            new RetryContext(0, 0),
        );
        self::assertSame(RetryDecisionReason::AT_MOST_ONCE, $atMostOnce->reason);

        $securityFailure = (new DeliverySemanticsEvaluator())->shouldRetry(
            $this->makeDeliveryAttempt(DeliverySafety::SIDE_EFFECT_FREE, DeliveryMode::RETRYABLE),
            $this->transportResult(TransportOutcome::AUTHORIZATION_FAILURE),
            new RetryPolicy(5),
            new RetryContext(0, 0),
        );
        self::assertSame(RetryDecisionReason::NOT_RETRYABLE, $securityFailure->reason);
    }

    public function testStateMachineForbidsImplicitIndeterminateAttempt(): void
    {
        $machine = new DeliveryStateMachine();
        $machine->transition(DeliveryState::ATTEMPTING);
        $machine->transition(DeliveryState::INDETERMINATE);
        $this->expectException(\LogicException::class);
        $machine->transition(DeliveryState::ATTEMPTING);
    }

    public function testStateMachineAllowsExplicitRetryScheduleAndAttempt(): void
    {
        $machine = new DeliveryStateMachine();
        $machine->transition(DeliveryState::ATTEMPTING);
        $machine->transition(DeliveryState::INDETERMINATE);
        $machine->transition(DeliveryState::RETRY_SCHEDULED);
        $machine->transition(DeliveryState::ATTEMPTING);
        self::assertSame(DeliveryState::ATTEMPTING, $machine->state());
    }

    public function testIdempotencyDuplicateAndConflictSemanticsAreStable(): void
    {
        $store = new BoundedInMemoryIdempotencyStore(2);
        self::assertSame(IdempotencyClaim::NEW, $store->claim('idem-1', 'fp-1'));
        self::assertSame(IdempotencyClaim::DUPLICATE, $store->claim('idem-1', 'fp-1'));
        self::assertSame(IdempotencyClaim::CONFLICT, $store->claim('idem-1', 'fp-2'));
    }

    public function testIdempotencyStoreCapacityIsBounded(): void
    {
        $store = new BoundedInMemoryIdempotencyStore(1);
        self::assertSame(IdempotencyClaim::NEW, $store->claim('idem-1', 'fp-1'));
        $this->expectException(\RuntimeException::class);
        $store->claim('idem-2', 'fp-2');
    }

    public function testSecurityBoundaryFailsClosedForAuthenticationFailures(): void
    {
        $boundary = new DefaultSecurityBoundary();
        $request = new SecurityRequest('mutation', 'orders', 'write');
        $policy = new class implements \Zef\Framework\Security\Distributed\AuthorizationPolicyInterface {
            #[\Override]
            public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
            {
                return new AuthorizationResult(SecurityVerdict::ALLOW, 'allow');
            }
        };
        $replay = new BoundedInMemoryReplayProtector();
        foreach ([AuthenticationStatus::FAILED, AuthenticationStatus::UNAVAILABLE, AuthenticationStatus::EXPIRED, AuthenticationStatus::UNAUTHENTICATED] as $status) {
            $decision = $boundary->admit(new AuthenticationResult($status), $request, $policy, $replay, 1000);
            self::assertSame(SecurityVerdict::DENY, $decision->verdict);
            self::assertFalse($decision->retryAllowed);
            self::assertNotSame(SecurityFailure::NONE, $decision->failure);
        }
    }

    public function testAuthorizationDeniedIsFailClosed(): void
    {
        $boundary = new DefaultSecurityBoundary();
        $context = new SecurityContext('principal', 'mTLS', 'orders:read', 'service-a', 'peer-a');
        $authentication = new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, $context);
        $request = new SecurityRequest('mutation', 'orders', 'write');
        $denyPolicy = new class implements \Zef\Framework\Security\Distributed\AuthorizationPolicyInterface {
            #[\Override]
            public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
            {
                return new AuthorizationResult(SecurityVerdict::DENY, 'policy-deny');
            }
        };
        $decision = $boundary->admit($authentication, $request, $denyPolicy, new BoundedInMemoryReplayProtector(), 1000);
        self::assertSame(SecurityVerdict::DENY, $decision->verdict);
        self::assertSame(SecurityFailure::AUTHORIZATION_DENIED, $decision->failure);
        self::assertFalse($decision->retryAllowed);
    }

    public function testReplayDuplicateAndCapacityAreDeniedWithoutRetryAuthority(): void
    {
        $boundary = new DefaultSecurityBoundary();
        $context = new SecurityContext('principal', 'mTLS', 'orders:read', 'service-a', 'peer-a');
        $authentication = new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, $context);
        $policy = new class implements \Zef\Framework\Security\Distributed\AuthorizationPolicyInterface {
            #[\Override]
            public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
            {
                return new AuthorizationResult(SecurityVerdict::ALLOW, 'allow');
            }
        };
        $replay = new BoundedInMemoryReplayProtector(1, 300_000);

        $first = $boundary->admit($authentication, new SecurityRequest('mutation', 'orders', 'write', 'r-1'), $policy, $replay, 1000);
        $duplicate = $boundary->admit($authentication, new SecurityRequest('mutation', 'orders', 'write', 'r-1'), $policy, $replay, 1001);
        self::assertSame(SecurityVerdict::ALLOW, $first->verdict);
        self::assertSame(SecurityVerdict::DENY, $duplicate->verdict);
        self::assertSame(SecurityFailure::REPLAY_REJECTED, $duplicate->failure);
        self::assertFalse($duplicate->retryAllowed);

        $capacity = $boundary->admit($authentication, new SecurityRequest('mutation', 'orders', 'write', 'r-2'), $policy, $replay, 1002);
        self::assertSame(SecurityVerdict::DENY, $capacity->verdict);
        self::assertSame(SecurityFailure::REPLAY_UNAVAILABLE, $capacity->failure);
        self::assertFalse($capacity->retryAllowed);
    }

    public function testReplayWindowExpiresOldIdentifiers(): void
    {
        $replay = new BoundedInMemoryReplayProtector(2, 100);
        self::assertSame(ReplayDecision::ACCEPT, $replay->check('r-1', 1000)->decision);
        self::assertSame(ReplayDecision::DUPLICATE, $replay->check('r-1', 1050)->decision);
        self::assertSame(ReplayDecision::ACCEPT, $replay->check('r-1', 1101)->decision);
    }

    public function testSensitiveSecurityMaterialCannotEnterGenericAttributes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecurityRequest('mutation', 'orders', 'write', null, ['authorization' => 'Bearer secret']);
    }

    public function testOversizedSecurityAttributesAndReplayIdsAreRejected(): void
    {
        try {
            new SecurityRequest('mutation', 'orders', 'write', str_repeat('x', SecurityRequest::MAX_REPLAY_ID_BYTES + 1));
            self::fail('Expected replay id bound rejection.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new SecurityRequest('mutation', 'orders', 'write', null, ['x' => str_repeat('v', SecurityRequest::MAX_ATTRIBUTE_VALUE_BYTES + 1)]);
            self::fail('Expected attribute value bound rejection.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testResourceAdmissionCanDenyRetryCapacityDeterministically(): void
    {
        $admission = new InMemoryAdmissionController(new ResourceBudget(1, 0));
        self::assertTrue($admission->admit()->admitted);
        $rejected = $admission->admit();
        self::assertFalse($rejected->admitted);
        self::assertSame('in_flight_limit', $rejected->reason);

        $operation = new DeliveryOperation('operation-1', 'mutation', DeliverySafety::SIDE_EFFECTING, DeliveryMode::IDEMPOTENT, 'idem-1', 'fp-1');
        $decision = (new DeliverySemanticsEvaluator(new BoundedInMemoryIdempotencyStore()))->shouldRetry(
            new DeliveryAttempt($operation, 1, 'attempt-1'),
            $this->transportResult(TransportOutcome::UNAVAILABLE),
            new RetryPolicy(3),
            new RetryContext(0, 0, $rejected->admitted, true),
        );
        self::assertFalse($decision->allowed);
        self::assertSame(RetryDecisionReason::RESOURCE_DENIED, $decision->reason);
    }
}
