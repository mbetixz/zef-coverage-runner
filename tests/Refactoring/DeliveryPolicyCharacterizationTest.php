<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Zef\Framework\Delivery\BoundedInMemoryIdempotencyStore;
use Zef\Framework\Delivery\DeliveryMode;
use Zef\Framework\Delivery\DeliveryOperation;
use Zef\Framework\Delivery\DeliverySafety;
use Zef\Framework\Delivery\DeliverySemanticsEvaluator;
use Zef\Framework\Delivery\DeliveryAttempt;
use Zef\Framework\Delivery\IdempotencyClaim;
use Zef\Framework\Delivery\RetryDecisionReason;
use Zef\Framework\Delivery\RetryPolicy;
use Zef\Framework\Delivery\RetryContext;
use Zef\Framework\Transport\RemoteTransportResult;
use Zef\Framework\Transport\TransportOutcome;

$pass = 0;
$fail = 0;
$check = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    if ($condition) { ++$pass; echo "PASS: {$message}\n"; return; }
    ++$fail; echo "FAIL: {$message}\n";
};
$throws = static function (string $class, callable $operation): bool {
    try { $operation(); } catch (Throwable $exception) { return $exception instanceof $class; }
    return false;
};

$operation = new DeliveryOperation('op-005', 'catalog.lookup', DeliverySafety::SIDE_EFFECT_FREE, DeliveryMode::RETRYABLE);
$attempt = new DeliveryAttempt($operation, 1, 'attempt-1');
$policy = new RetryPolicy(6, 10, 25, 0, 1000);
$check($policy->delayMs(2) === 10 && $policy->delayMs(3) === 20 && $policy->delayMs(4) === 25, 'retry backoff is deterministic and capped');
$check($throws(InvalidArgumentException::class, static fn() => $policy->delayMs(1)), 'retry delay rejects first-attempt number');
$check($throws(InvalidArgumentException::class, static fn() => new RetryPolicy(0)), 'retry policy rejects invalid attempt limit');
$check($throws(InvalidArgumentException::class, static fn() => new RetryContext(-1, 0)), 'retry context rejects negative time');

$evaluator = new DeliverySemanticsEvaluator();
$timeout = new RemoteTransportResult(TransportOutcome::TIMEOUT);
$decision = $evaluator->shouldRetry($attempt, $timeout, $policy, new RetryContext(10, 0));
$check($decision->allowed && $decision->reason === RetryDecisionReason::SAFE_TO_RETRY && $decision->nextAttemptNumber === 2, 'side-effect-free timeout is retryable');
$check($evaluator->shouldRetry($attempt, new RemoteTransportResult(TransportOutcome::SUCCESS), $policy, new RetryContext(0, 0))->reason === RetryDecisionReason::SUCCESS, 'success is not retried');
$check($evaluator->shouldRetry(new DeliveryAttempt($operation, 6, 'attempt-6'), $timeout, $policy, new RetryContext(0, 0))->reason === RetryDecisionReason::ATTEMPT_LIMIT_REACHED, 'attempt limit is enforced');
$check($evaluator->shouldRetry($attempt, $timeout, new RetryPolicy(5, 20, 100, 1000, 10), new RetryContext(0, 0))->reason === RetryDecisionReason::RETRY_BUDGET_EXHAUSTED, 'retry cumulative budget is enforced');
$check($evaluator->shouldRetry($attempt, $timeout, new RetryPolicy(5, 20, 100, 15, 100), new RetryContext(0, 0))->reason === RetryDecisionReason::DEADLINE_EXPIRED, 'retry deadline is enforced');
$check($evaluator->shouldRetry($attempt, $timeout, $policy, new RetryContext(0, 0, false, true))->reason === RetryDecisionReason::RESOURCE_DENIED, 'resource admission is enforced');
$check($evaluator->shouldRetry($attempt, $timeout, $policy, new RetryContext(0, 0, true, false))->reason === RetryDecisionReason::SECURITY_DENIED, 'security admission is enforced');

$store = new BoundedInMemoryIdempotencyStore(2);
$check($store->claim('key-1', 'fp-a') === IdempotencyClaim::NEW, 'first idempotency claim is new');
$check($store->claim('key-1', 'fp-a') === IdempotencyClaim::DUPLICATE, 'same idempotency claim is duplicate');
$check($store->claim('key-1', 'fp-b') === IdempotencyClaim::CONFLICT, 'different fingerprint is conflict');
$result = new RemoteTransportResult(TransportOutcome::SUCCESS, 'ok');
$store->complete('key-1', 'fp-a', $result);
$check($store->completedResult('key-1', 'fp-a')?->payload === 'ok', 'completed idempotency result is retained');
$check($throws(LogicException::class, static fn() => $store->completedResult('key-1', 'fp-b')), 'completed result fingerprint conflict rejected');
$store->claim('key-2', 'fp-c');
$check($throws(RuntimeException::class, static fn() => $store->claim('key-3', 'fp-d')), 'idempotency capacity is bounded');
$check($throws(InvalidArgumentException::class, static fn() => $store->claim('', 'fp')), 'empty idempotency key rejected');
$check($throws(InvalidArgumentException::class, static fn() => new DeliveryOperation('op', 'write', DeliverySafety::SIDE_EFFECTING, DeliveryMode::IDEMPOTENT)), 'idempotent operation requires key and fingerprint');

printf("Delivery policy characterization: %d pass, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
