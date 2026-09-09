<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\CounterMeter;
use Zef\Framework\Observability\InMemorySpanExporter;
use Zef\Framework\Observability\LogRecord;
use Zef\Framework\Observability\MeterInterface;
use Zef\Framework\Observability\MetricExporterInterface;
use Zef\Framework\Observability\NoopSpan;
use Zef\Framework\Observability\NoopTracer;
use Zef\Framework\Observability\Span;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Observability\SpanData;
use Zef\Framework\Observability\SpanExporterInterface;
use Zef\Framework\Observability\SpanInterface;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Observability\TelemetryClock;
use Zef\Framework\Observability\TelemetrySanitizer;
use Zef\Framework\Observability\TraceContextPropagator;
use Zef\Framework\Observability\Tracer;
use Zef\Framework\Observability\TracerInterface;

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
$isInstance = static function (mixed $value, string $class): bool {
    return $value instanceof $class;
};

foreach ([
    [SpanInterface::class, Span::class], [TracerInterface::class, Tracer::class],
    [MeterInterface::class, CounterMeter::class], [SpanExporterInterface::class, InMemorySpanExporter::class],
] as [$interface, $implementation]) {
    $check((new ReflectionClass($implementation))->implementsInterface($interface), "{$implementation} implements {$interface}");
}

$traceId = str_repeat('a', 32);
$spanId = str_repeat('b', 16);
$context = new SpanContext($traceId, $spanId, true, 'vendor=value');
$check($context->traceParent() === '00-' . $traceId . '-' . $spanId . '-01', 'traceparent serialization');
$check($context->isValid(), 'valid span context recognized');
$check(TraceContextPropagator::extract($context->traceParent(), 'vendor=value')?->traceId === $traceId, 'trace context extraction');
$check(TraceContextPropagator::inject($context) === $context->traceParent(), 'trace context injection');
$check(TraceContextPropagator::extract('invalid') === null, 'invalid trace context rejected');
$check(!$throws(InvalidArgumentException::class, static fn() => new SpanContext($traceId, $spanId)), 'valid context does not throw');
$check($throws(InvalidArgumentException::class, static fn() => new SpanContext('bad', $spanId)), 'invalid trace id rejected');
$check(!SpanContext::invalid()->isValid(), 'invalid sentinel context recognized');

$exporter = new InMemorySpanExporter();
$processor = new BatchSpanProcessor($exporter, 8, 8);
$ended = 0;
$span = new Span('checkout', $context, null, 100, 1_000, static function (SpanData $data) use (&$ended): void { ++$ended; });
$span->setAttribute('customer', 'alice')->setAttribute('password', 'secret')->addEvent('paid', ['amount' => 12, 'token' => 'hidden'])->setStatus('ok', str_repeat('x', 1100));
$span->end(250);
$span->end(300);
$check($span->isEnded() && $ended === 1, 'span end is idempotent');
$check($span->getContext()->traceId === $traceId, 'span context preserved');
$check($throws(InvalidArgumentException::class, static fn() => (new Span('x', $context, null, 1, 1, static fn(SpanData $data): null => null))->setStatus('bad')), 'invalid span status rejected');
$processor->onEnd(new SpanData('checkout', $context, null, 100, 250, 1_000, 1_150, 'OK', null, [], []));
$processor->flush();
$check(count($exporter->spans()) === 1, 'batch processor flushes queued spans');
$exporter->reset();
$check($exporter->spans() === [], 'in-memory exporter reset');
$check(NoopSpan::instance()->isEnded(), 'noop span is ended');
$check(class_exists(NoopTracer::class), 'noop tracer available');

$meter = new CounterMeter();
$meter->increment('zef.http.requests.total', 2, ['http.request.method' => 'GET', 'secret' => 'redact']);
$meter->observe('zef.http.duration', 1.5, ['http.response.status_code' => 200, 'extra' => 'drop']);
$snapshot = $meter->snapshot();
$check(count($snapshot) === 2, 'meter records distinct metric series');
$check(array_sum(array_map(static fn(array $metric): int|float => $metric['count'], $snapshot)) === 3, 'meter count aggregation');
$check(TelemetrySanitizer::isSensitiveKey('Authorization'), 'sensitive key detection');
$check(TelemetrySanitizer::attributes(['token' => 'x', 'safe' => 'ok']) === ['safe' => 'ok'], 'sensitive attributes removed');
$check(TelemetrySanitizer::string("a\x00b") === 'ab', 'control characters sanitized');
$check(TelemetrySanitizer::string(str_repeat('a', 10), 4) === 'aaaa…', 'string length bounded with ellipsis');
$check(TelemetryClock::nowNs() > 0 && TelemetryClock::nowUnixNano() > 0, 'telemetry clocks return positive timestamps');
$disabled = new Telemetry(new NoopTracer(), new CounterMeter(), new BatchSpanProcessor(new InMemorySpanExporter()), null, null, false);
$check(!$disabled->isEnabled() && $disabled->startSpan('disabled') instanceof NoopSpan, 'disabled telemetry uses noop span');
$disabled->recordLog('INFO', 'ignored');
$disabled->flush();
$disabled->shutdown();
$check($disabled->extract($context->traceParent())?->spanId === $spanId, 'telemetry delegates context extraction');
$check($isInstance($disabled->tracer(), TracerInterface::class) && $isInstance($disabled->meter(), MeterInterface::class), 'telemetry exposes tracer and meter');

$check($throws(InvalidArgumentException::class, static fn() => new BatchSpanProcessor($exporter, 0, 1)), 'invalid processor queue rejected');
$check($throws(InvalidArgumentException::class, static fn() => new BatchSpanProcessor($exporter, 1, 0)), 'invalid processor batch rejected');

printf("Observability characterization: %d pass, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
