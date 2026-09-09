<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Zef\Framework\Delivery\BoundedInMemoryIdempotencyStore;
use Zef\Framework\Delivery\DeliveryAttempt;
use Zef\Framework\Delivery\DeliveryMode;
use Zef\Framework\Delivery\DeliveryOperation;
use Zef\Framework\Delivery\DeliverySemanticsEvaluator;
use Zef\Framework\Delivery\RetryContext;
use Zef\Framework\Delivery\RetryPolicy;
use Zef\Framework\Transport\RemoteTransportResult;
use Zef\Framework\Transport\TransportOutcome;

$store = new BoundedInMemoryIdempotencyStore();
$operation = new DeliveryOperation('smoke-op', 'smoke', \Zef\Framework\Delivery\DeliverySafety::SIDE_EFFECTING, DeliveryMode::IDEMPOTENT, 'smoke-key', 'smoke-fp');
$attempt = new DeliveryAttempt($operation, 1, 'smoke-attempt-1');
$decision = (new DeliverySemanticsEvaluator($store))->shouldRetry(
    $attempt,
    new RemoteTransportResult(TransportOutcome::TIMEOUT),
    new RetryPolicy(3, 5, 20, 100, 100),
    new RetryContext(10, 0),
);
if (!$decision->allowed || $decision->delayMs !== 5) {
    fwrite(STDERR, "W3 native contract smoke FAIL\n");
    exit(1);
}
echo "G4.8 W3 native contract smoke PASS".PHP_EOL;
