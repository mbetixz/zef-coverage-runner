<?php
declare(strict_types=1);
require __DIR__.'/_assert.php';

$root = dirname(__DIR__, 2);
$source = (string) file_get_contents($root.'/src/Framework/Delivery/Delivery.php');
$expectedTypes = [
    'DeliverySafety','DeliveryMode','ExecutionCertainty','IdempotencyClaim','DeliveryState',
    'RetryDecisionReason','DeliveryOperation','DeliveryObservation','DeliveryAttempt','RetryPolicy',
    'RetryContext','RetryDecision','IdempotencyGuaranteeInterface','IdempotencyStoreInterface',
    'IdempotencyRecord','BoundedInMemoryIdempotencyStore','DeliveryReconciliationInterface','DeliveryResult',
    'DeliveryStateMachine','DeliverySemanticsEvaluator',
];
foreach ($expectedTypes as $type) {
    architecture_check((bool) preg_match('/\\b(?:class|interface|enum)\\s+'.preg_quote($type, '/').'\\b/', $source), 'W3 declared public type: '.$type);
}
foreach (['delayMs','supports','claim','complete','completedResult','reconcile','state','transition','executionCertainty','shouldRetry','shouldRetryObservation'] as $method) {
    architecture_check(str_contains($source, 'public function '.$method.'('), 'W3 declared public method: '.$method);
}
$delta = json_decode((string) file_get_contents(__DIR__.'/public-surface-delta-v2.5.0-beta1-g4.8-w3.json'), true);
/** @var array{classes?: array<mixed>, methods?: array<mixed>} $delta */
architecture_check(count($delta['classes'] ?? []) === 20, 'W3 delta declares exactly 20 public types');
architecture_check(count($delta['methods'] ?? []) === 11, 'W3 delta declares exactly 11 named public methods');
echo "G4.8 W3 public surface governance PASS".PHP_EOL;
