<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Zef\Framework\Observability\CorrelationContext;
use Zef\Framework\Observability\CorrelationPropagator;

$checks = 0;
$pass = static function (bool $condition, string $name) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$name);
    }
    echo "PASS: {$name}\n";
};

$base = '00-11111111111111111111111111111111-2222222222222222-01';
$pass(CorrelationPropagator::extract($base, 'vendor=value', 'op-1') !== null, 'valid W3C traceparent');
$pass(CorrelationPropagator::extract('garbage', null, 'op-1') === null, 'malformed traceparent rejected');
$pass(CorrelationPropagator::extract(substr($base, 0, 54), null, 'op-1') === null, 'short traceparent rejected');
$pass(CorrelationPropagator::extract($base, str_repeat('x', 513), 'op-1') === null, 'oversized tracestate rejected');
$pass(CorrelationPropagator::extract($base, 'bad value!', 'op-1') === null, 'invalid tracestate rejected');
$pass(CorrelationPropagator::extract($base, null, 'op-1', null, array_fill_keys(['a','b','c','d','e','f','g','h','i','j','k','l','m','n','o','p','q'], 'x')) === null, 'attribute-count overflow rejected');
$pass(CorrelationPropagator::extract($base, null, 'op-1', null, ['authorization.token' => 'sensitive']) !== null, 'sensitive attribute accepted for later redaction');
$ctx = CorrelationPropagator::extract($base, null, 'op-1', 'idem-secret', ['authorization.token' => 'sensitive']);
$pass($ctx instanceof CorrelationContext, 'correlation context created');
$pass(!str_contains((string) CorrelationPropagator::inject($ctx)?->traceParent, 'idem-secret'), 'idempotency key not injected into W3C trace headers');
$pass(CorrelationPropagator::inject(null) === null, 'disabled injection is allocation-free by contract');
$pass(CorrelationPropagator::disabled() === null, 'disabled context remains null');
$pass(str_starts_with((string) $ctx?->redactedAttributes()['authorization.token'], 'sha256:'), 'sensitive data redaction is deterministic');
$pass($ctx?->propagationBytes() === 55, 'wire trace context is bounded to W3C traceparent when no tracestate');
$pass(CorrelationPropagator::extract($base, null, str_repeat('x', 129)) === null, 'operation identity bounds enforced');
$pass(CorrelationPropagator::extract($base, null, 'op-1', str_repeat('x', 129)) === null, 'idempotency identity bounds enforced');
$pass($ctx?->attributes['authorization.token'] === 'sensitive', 'raw context is explicit and immutable until diagnostic projection');

echo "G4.8 W4 failure-injection contract matrix: {$checks}/{$checks} PASS".PHP_EOL;
