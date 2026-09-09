<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

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
use Zef\Framework\Delivery\RetryContext;
use Zef\Framework\Delivery\RetryDecisionReason;
use Zef\Framework\Delivery\RetryPolicy;
use Zef\Framework\Transport\RemoteTransportResult;
use Zef\Framework\Transport\TransportOutcome;

$checks = [];
$assert = static function (bool $ok, string $name) use (&$checks): void {
    $checks[] = [$name, $ok];
    if (!$ok) { throw new RuntimeException('FAIL: '.$name); }
    echo "PASS: {$name}\n";
};
$store = new BoundedInMemoryIdempotencyStore(16);
$evaluator = new DeliverySemanticsEvaluator($store);
$policy = new RetryPolicy(3, 10, 25, 100, 50);
$side = static fn(DeliveryMode $m): DeliveryOperation => new DeliveryOperation('op-'.$m->value, 'orders.charge', DeliverySafety::SIDE_EFFECTING, $m, $m === DeliveryMode::IDEMPOTENT ? 'idem-'.$m->value : ($m === DeliveryMode::RETRYABLE ? 'idem-retry' : null), $m === DeliveryMode::IDEMPOTENT ? 'fp-'.$m->value : ($m === DeliveryMode::RETRYABLE ? 'fp-retry' : null));
$read = new DeliveryOperation('read-1', 'catalog.lookup', DeliverySafety::SIDE_EFFECT_FREE, DeliveryMode::RETRYABLE);

$readDecision = $evaluator->shouldRetry(new DeliveryAttempt($read, 1, 'a1'), new RemoteTransportResult(TransportOutcome::TIMEOUT), $policy, new RetryContext(10, 0));
$assert($readDecision->allowed, 'side-effect-free timeout is retryable');

$unsafe = new DeliveryOperation('unsafe-1', 'orders.charge', DeliverySafety::SIDE_EFFECTING, DeliveryMode::RETRYABLE);
$unsafeDecision = $evaluator->shouldRetry(new DeliveryAttempt($unsafe, 1, 'u1'), new RemoteTransportResult(TransportOutcome::TIMEOUT), $policy, new RetryContext(10, 0));
$assert(!$unsafeDecision->allowed && $unsafeDecision->reason === RetryDecisionReason::IDEMPOTENCY_GUARANTEE_REQUIRED, 'timeout after possible transmission fails closed without idempotency');

$obs = new DeliveryObservation(new RemoteTransportResult(TransportOutcome::TIMEOUT), ExecutionCertainty::DEFINITELY_NOT_EXECUTED);
$observedDecision = $evaluator->shouldRetryObservation(new DeliveryAttempt($unsafe, 1, 'u2'), $obs, $policy, new RetryContext(10, 0));
$assert(!$observedDecision->allowed, 'explicit non-executed side-effect retry still requires configured safety policy');

$idem = $side(DeliveryMode::IDEMPOTENT);
$idemDecision = $evaluator->shouldRetry(new DeliveryAttempt($idem, 1, 'i1'), new RemoteTransportResult(TransportOutcome::INDETERMINATE), $policy, new RetryContext(10, 0));
$assert($idemDecision->allowed && $idemDecision->reason === RetryDecisionReason::SAFE_TO_RETRY, 'indeterminate with valid idempotency is bounded-retry eligible');

$remoteFailureDecision = $evaluator->shouldRetry(new DeliveryAttempt($idem, 1, 'i2'), new RemoteTransportResult(TransportOutcome::REMOTE_PROCESSING_FAILURE), $policy, new RetryContext(10, 0));
$assert(!$remoteFailureDecision->allowed && $remoteFailureDecision->reason === RetryDecisionReason::DEFINITIVELY_EXECUTED, 'remote processing failure is not retried after execution');

$authDecision = $evaluator->shouldRetry(new DeliveryAttempt($idem, 1, 'i3'), new RemoteTransportResult(TransportOutcome::AUTHORIZATION_FAILURE), $policy, new RetryContext(10, 0));
$assert(!$authDecision->allowed, 'authorization failure is never automatically retried');

$budgetDecision = $evaluator->shouldRetry(new DeliveryAttempt($idem, 1, 'i4'), new RemoteTransportResult(TransportOutcome::UNAVAILABLE), new RetryPolicy(5, 20, 100, 1000, 10), new RetryContext(0, 0));
$assert(!$budgetDecision->allowed && $budgetDecision->reason === RetryDecisionReason::RETRY_BUDGET_EXHAUSTED, 'retry budget is hard bounded');

$deadlineDecision = $evaluator->shouldRetry(new DeliveryAttempt($idem, 1, 'i5'), new RemoteTransportResult(TransportOutcome::UNAVAILABLE), new RetryPolicy(5, 20, 100, 15, 100), new RetryContext(0, 0));
$assert(!$deadlineDecision->allowed && $deadlineDecision->reason === RetryDecisionReason::DEADLINE_EXPIRED, 'parent deadline prevents retry');

$resourceDecision = $evaluator->shouldRetry(new DeliveryAttempt($idem, 1, 'i6'), new RemoteTransportResult(TransportOutcome::UNAVAILABLE), $policy, new RetryContext(10, 0, false, true));
$assert(!$resourceDecision->allowed && $resourceDecision->reason === RetryDecisionReason::RESOURCE_DENIED, 'resource denial prevents retry');

$securityDecision = $evaluator->shouldRetry(new DeliveryAttempt($idem, 1, 'i7'), new RemoteTransportResult(TransportOutcome::UNAVAILABLE), $policy, new RetryContext(10, 0, true, false));
$assert(!$securityDecision->allowed && $securityDecision->reason === RetryDecisionReason::SECURITY_DENIED, 'security denial prevents retry');

$assert($store->claim('dedupe-1', 'fp-1')->value === 'new', 'first idempotency claim reserves key');
$assert($store->claim('dedupe-1', 'fp-1')->value === 'duplicate', 'duplicate claim is detected');
$assert($store->claim('dedupe-1', 'fp-2')->value === 'conflict', 'same key with different fingerprint is rejected');

$machine = new DeliveryStateMachine();
$machine->transition(DeliveryState::ATTEMPTING);
$machine->transition(DeliveryState::INDETERMINATE);
try { $machine->transition(DeliveryState::ATTEMPTING); $ok=false; } catch (LogicException) { $ok=true; }
$assert($ok, 'state machine has no implicit indeterminate-to-attempt transition');
$machine->transition(DeliveryState::RETRY_SCHEDULED);
$machine->transition(DeliveryState::ATTEMPTING);
$assert($machine->state() === DeliveryState::ATTEMPTING, 'retry requires explicit RETRY_SCHEDULED state');

echo 'G4.8 W3 failure-injection contract matrix: '.count($checks).'/'.count($checks).' PASS'.PHP_EOL;
