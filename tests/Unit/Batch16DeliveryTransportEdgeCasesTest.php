<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Zef\Framework\Delivery\BoundedInMemoryIdempotencyStore;
    use Zef\Framework\Delivery\DeliveryAttempt;
    use Zef\Framework\Delivery\DeliveryMode;
    use Zef\Framework\Delivery\DeliveryObservation;
    use Zef\Framework\Delivery\DeliveryOperation;
    use Zef\Framework\Delivery\DeliveryResult;
    use Zef\Framework\Delivery\DeliverySafety;
    use Zef\Framework\Delivery\DeliverySemanticsEvaluator;
    use Zef\Framework\Delivery\DeliveryState;
    use Zef\Framework\Delivery\ExecutionCertainty;
    use Zef\Framework\Delivery\IdempotencyClaim;
    use Zef\Framework\Delivery\RetryContext;
    use Zef\Framework\Delivery\RetryDecisionReason;
    use Zef\Framework\Delivery\RetryPolicy;
    use Zef\Framework\Transport\RemoteTransportResult;
    use Zef\Framework\Transport\TransportOutcome;

    /**
     * Batch 16 coverage: Delivery/Transport edge-case branches previously
     * uncovered (DeliverySemanticsEvaluator decision short-circuits at
     * lines 104/119, DeliveryResult constructor guard, DeliveryStateMachine
     * illegal-transition guard, aggregate-require compatibility files under
     * src/Framework/Delivery and src/Framework/Transport, plus
     * BoundedInMemoryIdempotencyStore guards).
     *
     * Determinism: pure in-memory evaluation, no network, no wall-clock
     * assertions. Supply-chain note: no new dependencies; production code
     * untouched. Lives in tests/Unit — never tests/Regression/.
     */
    final class Batch16DeliveryTransportEdgeCasesTest extends TestCase
    {
        /**
         * @return array<int, array{TransportOutcome}>
         */
        public static function nonSuccessOutcomes(): array
        {
            return [
                [TransportOutcome::REJECTED],
                [TransportOutcome::TIMEOUT],
                [TransportOutcome::UNAVAILABLE],
                [TransportOutcome::TRANSPORT_FAILURE],
                [TransportOutcome::AUTHENTICATION_FAILURE],
                [TransportOutcome::AUTHORIZATION_FAILURE],
                [TransportOutcome::REMOTE_PROCESSING_FAILURE],
                [TransportOutcome::INDETERMINATE],
                [TransportOutcome::CANCELLED],
            ];
        }

        /**
         * @return array<int, array{TransportOutcome}>
         */
        public static function indefiniteOutcomes(): array
        {
            return [
                [TransportOutcome::TIMEOUT],
                [TransportOutcome::UNAVAILABLE],
                [TransportOutcome::TRANSPORT_FAILURE],
                [TransportOutcome::INDETERMINATE],
                [TransportOutcome::CANCELLED],
            ];
        }

        private function operation(
            DeliveryMode $mode = DeliveryMode::IDEMPOTENT,
            ?string $key = 'idem-key-1',
            ?string $fingerprint = 'fp-1',
        ): DeliveryOperation {
            return new DeliveryOperation(
                'op-s-retry',
                'orders.charge',
                DeliverySafety::SIDE_EFFECTING,
                $mode,
                $key,
                $fingerprint,
            );
        }

        private function makeAttempt(DeliveryOperation $operation, int $number = 1): DeliveryAttempt
        {
            return new DeliveryAttempt($operation, $number, 'attempt-' . $number);
        }

        public function testShouldRetryObservationWithDefinitivelyExecutedResult(): void
        {
            $evaluator = new DeliverySemanticsEvaluator();
            $observation = new DeliveryObservation(
                new RemoteTransportResult(TransportOutcome::REMOTE_PROCESSING_FAILURE),
                ExecutionCertainty::DEFINITELY_EXECUTED,
            );
            $decision = $evaluator->shouldRetryObservation(
                $this->makeAttempt($this->operation()),
                $observation,
                new RetryPolicy(3, 10, 100, 1000, 1000),
                new RetryContext(10, 0),
            );
            self::assertFalse($decision->allowed);
            self::assertSame(RetryDecisionReason::DEFINITIVELY_EXECUTED, $decision->reason);
        }

        /**
         * @param TransportOutcome $outcome
         */
        #[DataProvider('nonSuccessOutcomes')]
        public function testOutcomeClassifications(TransportOutcome $outcome): void
        {
            $evaluator = new DeliverySemanticsEvaluator();
            $certainty = $evaluator->executionCertainty(new RemoteTransportResult($outcome));
            self::assertContains($certainty, [
                ExecutionCertainty::DEFINITELY_EXECUTED,
                ExecutionCertainty::DEFINITELY_NOT_EXECUTED,
                ExecutionCertainty::INDETERMINATE,
            ]);
        }

        /**
         * @param TransportOutcome $outcome
         */
        #[DataProvider('indefiniteOutcomes')]
        public function testExecutionCertaintyIndeterminateForNonProofOutcomes(TransportOutcome $outcome): void
        {
            $evaluator = new DeliverySemanticsEvaluator();
            self::assertSame(
                ExecutionCertainty::INDETERMINATE,
                $evaluator->executionCertainty(new RemoteTransportResult($outcome)),
            );
        }

        public function testExecutionCertaintyForRejectedAndAuthOutcomes(): void
        {
            $evaluator = new DeliverySemanticsEvaluator();
            self::assertSame(
                ExecutionCertainty::DEFINITELY_NOT_EXECUTED,
                $evaluator->executionCertainty(new RemoteTransportResult(TransportOutcome::REJECTED)),
            );
            self::assertSame(
                ExecutionCertainty::DEFINITELY_NOT_EXECUTED,
                $evaluator->executionCertainty(new RemoteTransportResult(TransportOutcome::AUTHENTICATION_FAILURE)),
            );
            self::assertSame(
                ExecutionCertainty::DEFINITELY_NOT_EXECUTED,
                $evaluator->executionCertainty(new RemoteTransportResult(TransportOutcome::AUTHORIZATION_FAILURE)),
            );
        }

        public function testDeadlineExpiredShortCircuit(): void
        {
            $evaluator = new DeliverySemanticsEvaluator(new BoundedInMemoryIdempotencyStore());
            // monotonicNowMs already past the policy deadline -> DEADLINE_EXPIRED
            // without consulting retryability (previously uncovered line 119).
            $decision = $evaluator->shouldRetry(
                $this->makeAttempt($this->operation()),
                new RemoteTransportResult(TransportOutcome::TIMEOUT),
                new RetryPolicy(3, 10, 100, 50, 1000),
                new RetryContext(5000, 0),
            );
            self::assertFalse($decision->allowed);
            self::assertSame(RetryDecisionReason::DEADLINE_EXPIRED, $decision->reason);
        }

        public function testSuccessShortCircuit(): void
        {
            $evaluator = new DeliverySemanticsEvaluator();
            $decision = $evaluator->shouldRetry(
                $this->makeAttempt($this->operation()),
                new RemoteTransportResult(TransportOutcome::SUCCESS),
                new RetryPolicy(3, 10, 100, 1000, 1000),
                new RetryContext(10, 0),
            );
            self::assertFalse($decision->allowed);
            self::assertSame(RetryDecisionReason::SUCCESS, $decision->reason);
        }

        public function testDeliveryResultConstructorGuard(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            new DeliveryResult(DeliveryState::DEFINITIVE_SUCCESS, 65);
        }

        public function testDeliveryStateMachineIllegalTransitionGuard(): void
        {
            $machine = new \Zef\Framework\Delivery\DeliveryStateMachine();
            self::assertSame(DeliveryState::PREPARED, $machine->state());
            $machine->transition(DeliveryState::ATTEMPTING);
            $this->expectException(\LogicException::class);
            $machine->transition(DeliveryState::PREPARED);
        }

        public function testLegacyAggregateRequiresAreLoadable(): void
        {
            // The compatibility aggregates exist solely to keep legacy
            // `require`-based consumers working; loading them must not
            // redeclare any per-type symbol.
            require_once \dirname(__DIR__, 2) . '/src/Framework/Delivery/DeliveryTypes.php';
            require_once \dirname(__DIR__, 2) . '/src/Framework/Delivery/DeliverySemantics.php';
            require_once \dirname(__DIR__, 2) . '/src/Framework/Delivery/Delivery.php';
            require_once \dirname(__DIR__, 2) . '/src/Framework/Delivery/Idempotency.php';
            require_once \dirname(__DIR__, 2) . '/src/Framework/Transport/Transport.php';
            self::assertTrue(interface_exists(\Zef\Framework\Delivery\IdempotencyStoreInterface::class));
            self::assertTrue(enum_exists(\Zef\Framework\Delivery\DeliverySafety::class));
            self::assertTrue(class_exists(\Zef\Framework\Delivery\DeliveryResult::class));
            self::assertTrue(enum_exists(\Zef\Framework\Transport\TransportOutcome::class));
            $this->addToAssertionCount(1);
        }

        public function testBoundedStoreCapacityGuard(): void
        {
            $store = new BoundedInMemoryIdempotencyStore(2);
            $first = $store->claim('k-1', 'fp-1');
            self::assertSame(IdempotencyClaim::NEW, $first);
            $second = $store->claim('k-2', 'fp-2');
            self::assertSame(IdempotencyClaim::NEW, $second);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('capacity exhausted');
            $store->claim('k-3', 'fp-3');
        }

        public function testBoundedStoreDuplicateClaimAndCompletedResult(): void
        {
            $store = new BoundedInMemoryIdempotencyStore();
            $operation = $this->operation(DeliveryMode::IDEMPOTENT);
            self::assertTrue($store->supports($operation));
            self::assertNull($store->completedResult('idem-key-1', 'fp-1'));
            self::assertSame(IdempotencyClaim::NEW, $store->claim('idem-key-1', 'fp-1'));
            self::assertSame(IdempotencyClaim::DUPLICATE, $store->claim('idem-key-1', 'fp-1'));
            $store->complete('idem-key-1', 'fp-1', new RemoteTransportResult(TransportOutcome::SUCCESS, 'ok'));
            $result = $store->completedResult('idem-key-1', 'fp-1');
            self::assertNotNull($result);
            self::assertSame(TransportOutcome::SUCCESS, $result->outcome);
        }
    }
}
