<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    use Zef\Framework\Transport\RemoteTransportResult;
    use Zef\Framework\Transport\TransportOutcome;

    /**
     * Pure evaluator mapping transport outcomes and delivery context to
     * execution certainty and retry decisions. Stateless: every public method
     * is a function of its arguments plus the optional idempotency guarantee.
     * The evaluator never performs the retry itself; it only decides.
     */
    final class DeliverySemanticsEvaluator
    {
        /** @param IdempotencyGuaranteeInterface|null $idempotency optional guarantee consulted before retrying side-effecting operations */
        public function __construct(private readonly ?IdempotencyGuaranteeInterface $idempotency = null)
        {
        }

        /**
         * Classify how certain we are that the remote executed the operation.
         *
         * SUCCESS / REMOTE_PROCESSING_FAILURE prove execution;
         * REJECTED / AUTHENTICATION_FAILURE / AUTHORIZATION_FAILURE prove
         * non-execution (remote refused before running); all other outcomes
         * (timeout/unavailable/transport failure/indeterminate/cancelled) give
         * no proof and are classified INDETERMINATE.
         */
        public function executionCertainty(RemoteTransportResult $result): ExecutionCertainty
        {
            return match ($result->outcome) {
                TransportOutcome::SUCCESS => ExecutionCertainty::DEFINITELY_EXECUTED,
                TransportOutcome::REJECTED,
                TransportOutcome::AUTHENTICATION_FAILURE,
                TransportOutcome::AUTHORIZATION_FAILURE => ExecutionCertainty::DEFINITELY_NOT_EXECUTED,
                TransportOutcome::REMOTE_PROCESSING_FAILURE => ExecutionCertainty::DEFINITELY_EXECUTED,
                TransportOutcome::TIMEOUT,
                TransportOutcome::UNAVAILABLE,
                TransportOutcome::TRANSPORT_FAILURE,
                TransportOutcome::INDETERMINATE,
                TransportOutcome::CANCELLED => ExecutionCertainty::INDETERMINATE,
            };
        }

        /**
         * Decide whether to retry a delivery attempt from a live transport
         * result. Delegates to the certainty-aware decision core.
         */
        public function shouldRetry(
            DeliveryAttempt $attempt,
            RemoteTransportResult $result,
            RetryPolicy $policy,
            RetryContext $context,
        ): RetryDecision {
            return $this->shouldRetryWithCertainty(
                $attempt,
                $result,
                $this->executionCertainty($result),
                $policy,
                $context,
            );
        }

        /**
         * Decide whether to retry from an already-classified observation.
         *
         * A DEFINITELY_EXECUTED observation whose result is not SUCCESS never
         * retries (the operation ran; retrying would duplicate it).
         */
        public function shouldRetryObservation(
            DeliveryAttempt $attempt,
            DeliveryObservation $observation,
            RetryPolicy $policy,
            RetryContext $context,
        ): RetryDecision {
            $certainty = $observation->certainty;
            if ($certainty === ExecutionCertainty::DEFINITELY_EXECUTED && $observation->result->outcome !== TransportOutcome::SUCCESS) {
                return new RetryDecision(false, RetryDecisionReason::DEFINITIVELY_EXECUTED, $attempt->attemptNumber + 1, 0);
            }
            return $this->shouldRetryWithCertainty($attempt, $observation->result, $certainty, $policy, $context);
        }

        /**
         * Decision core shared by both public entry points.
         *
         * Short-circuits in order: success -> no; definite execution -> no;
         * attempt limit -> no; resource/security denial -> no; deadline passed
         * -> no; non-retryable outcome -> no; at-most-once mode -> no;
         * side-effecting without idempotency guarantee -> no; delay would
         * exceed the cumulative budget or the deadline -> no. Otherwise
         * SAFE_TO_RETRY with the policy backoff delay.
         */
        private function shouldRetryWithCertainty(
            DeliveryAttempt $attempt,
            RemoteTransportResult $result,
            ExecutionCertainty $certainty,
            RetryPolicy $policy,
            RetryContext $context,
        ): RetryDecision {
            $nextAttempt = $attempt->attemptNumber + 1;
            if ($result->outcome === TransportOutcome::SUCCESS) {
                return new RetryDecision(false, RetryDecisionReason::SUCCESS, $nextAttempt, 0);
            }
            if ($certainty === ExecutionCertainty::DEFINITELY_EXECUTED) {
                return new RetryDecision(false, RetryDecisionReason::DEFINITIVELY_EXECUTED, $nextAttempt, 0);
            }
            if ($attempt->attemptNumber >= $policy->maxAttempts) {
                return new RetryDecision(false, RetryDecisionReason::ATTEMPT_LIMIT_REACHED, $nextAttempt, 0);
            }
            if (!$context->resourceAdmitted) {
                return new RetryDecision(false, RetryDecisionReason::RESOURCE_DENIED, $nextAttempt, 0);
            }
            if (!$context->securityAllowed) {
                return new RetryDecision(false, RetryDecisionReason::SECURITY_DENIED, $nextAttempt, 0);
            }
            if ($policy->deadlineMs > 0 && $context->monotonicNowMs >= $policy->deadlineMs) {
                return new RetryDecision(false, RetryDecisionReason::DEADLINE_EXPIRED, $nextAttempt, 0);
            }
            if (!$this->retryableOutcome($result->outcome)) {
                return new RetryDecision(false, RetryDecisionReason::NOT_RETRYABLE, $nextAttempt, 0);
            }
            if ($attempt->operation->mode === DeliveryMode::AT_MOST_ONCE) {
                return new RetryDecision(false, RetryDecisionReason::AT_MOST_ONCE, $nextAttempt, 0);
            }
            if ($attempt->operation->safety === DeliverySafety::SIDE_EFFECTING && !$this->hasGuarantee($attempt->operation)) {
                return new RetryDecision(false, RetryDecisionReason::IDEMPOTENCY_GUARANTEE_REQUIRED, $nextAttempt, 0);
            }

            $delay = $policy->delayMs($nextAttempt);
            if ($context->cumulativeDelayMs + $delay > $policy->maxCumulativeDelayMs) {
                return new RetryDecision(false, RetryDecisionReason::RETRY_BUDGET_EXHAUSTED, $nextAttempt, 0);
            }
            if ($policy->deadlineMs > 0 && $context->monotonicNowMs + $delay >= $policy->deadlineMs) {
                return new RetryDecision(false, RetryDecisionReason::DEADLINE_EXPIRED, $nextAttempt, 0);
            }
            return new RetryDecision(true, RetryDecisionReason::SAFE_TO_RETRY, $nextAttempt, $delay);
        }

        /**
         * True when the operation carries an idempotency key + fingerprint and
         * the optional guarantee store supports it.
         */
        private function hasGuarantee(DeliveryOperation $operation): bool
        {
            return $operation->idempotencyKey !== null
                && $operation->operationFingerprint !== null
                && $this->idempotency?->supports($operation) === true;
        }

        /**
         * Outcomes that are safe to retry when the operation is retryable and
         * guarded: rejected/timeout/unavailable/transport failure/indeterminate.
         * Remote processing failure is NOT retryable (the remote executed it).
         */
        private function retryableOutcome(TransportOutcome $outcome): bool
        {
            return match ($outcome) {
                TransportOutcome::REJECTED,
                TransportOutcome::TIMEOUT,
                TransportOutcome::UNAVAILABLE,
                TransportOutcome::TRANSPORT_FAILURE,
                TransportOutcome::INDETERMINATE => true,
                TransportOutcome::REMOTE_PROCESSING_FAILURE => false,
                TransportOutcome::AUTHENTICATION_FAILURE,
                TransportOutcome::AUTHORIZATION_FAILURE,
                TransportOutcome::SUCCESS,
                TransportOutcome::CANCELLED => false,
            };
        }
    }
}
