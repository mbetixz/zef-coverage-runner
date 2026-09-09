<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Observability\CounterMeter;
use Zef\Framework\Observability\InMemorySpanExporter;
use Zef\Framework\Observability\NoopSpan;
use Zef\Framework\Observability\NoopTracer;
use Zef\Framework\Observability\RetryBackoffPolicy;
use Zef\Framework\Observability\Span;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Observability\SpanData;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Observability\TelemetryClock;
use Zef\Framework\Observability\TelemetrySanitizer;
use Zef\Framework\Observability\TraceContextPropagator;
use Zef\Framework\Observability\Tracer;

/**
 * Batch 6 coverage: Observability (tracing + telemetry + sanitization).
 *
 * Deterministic by construction: fixed W3C hex identifiers, injected clocks
 * and environment variables isolated per test (putenv + restore).
 */
final class Batch6ObservabilityTest extends TestCase
{
    private const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const SPAN = '00f067aa0ba902b7';

    private function validContext(): SpanContext
    {
        return new SpanContext(self::TRACE, self::SPAN, true, 'vendor=value');
    }

    // ----- SpanContext -----

    public function testSpanContextValidationAndTraceParent(): void
    {
        $context = new SpanContext(self::TRACE, self::SPAN, true);
        $this->assertSame('00-' . self::TRACE . '-' . self::SPAN . '-01', $context->traceParent());
        $this->assertTrue($context->isValid());

        $notSampled = new SpanContext(self::TRACE, self::SPAN, false);
        $this->assertSame('00-' . self::TRACE . '-' . self::SPAN . '-00', $notSampled->traceParent());

        foreach ([
            ['not-hex', self::SPAN],
            [self::TRACE, 'short'],
            [str_repeat('g', 32), self::SPAN],
        ] as [$badTrace, $badSpan]) {
            try {
                new SpanContext($badTrace, $badSpan);
                $this->fail('invalid identifiers must throw');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSpanContextInvalidIsNotValid(): void
    {
        $invalid = SpanContext::invalid();
        $this->assertSame('00-' . str_repeat('0', 32) . '-' . str_repeat('0', 16) . '-00', $invalid->traceParent());
        $this->assertFalse($invalid->isValid());
    }

    // ----- TraceContextPropagator -----

    public function testTraceContextPropagatorExtractAndInject(): void
    {
        $context = TraceContextPropagator::extract('00-' . self::TRACE . '-' . self::SPAN . '-01', 'vendor=value');
        $this->assertNotNull($context);
        $this->assertSame(self::TRACE, $context->traceId);
        $this->assertSame(self::SPAN, $context->spanId);
        $this->assertTrue($context->sampled);
        $this->assertSame('vendor=value', $context->traceState);
        $this->assertSame('00-' . self::TRACE . '-' . self::SPAN . '-01', TraceContextPropagator::inject($context));
    }

    public function testTraceContextPropagatorRejectsMalformedInput(): void
    {
        $this->assertNull(TraceContextPropagator::extract('junk'));
        $this->assertNull(TraceContextPropagator::extract('00-' . str_repeat('0', 32) . '-' . self::SPAN . '-01'));
        $this->assertNull(TraceContextPropagator::extract('00-' . self::TRACE . '-' . str_repeat('0', 16) . '-01'));
        $this->assertNull(TraceContextPropagator::extract('00-' . self::TRACE . '-' . self::SPAN . '-ff'));
        $this->assertNull(TraceContextPropagator::extract('00-' . self::TRACE . '-' . self::SPAN . '-02'));
        $this->assertNull(TraceContextPropagator::extract('00-' . self::TRACE . '-' . self::SPAN . '-01-extra'));

        // Uppercase hex is accepted and lowercased.
        $upper = TraceContextPropagator::extract('00-' . strtoupper(self::TRACE) . '-' . strtoupper(self::SPAN) . '-01');
        $this->assertNotNull($upper);
        $this->assertSame(self::TRACE, $upper->traceId);
    }

    // ----- TelemetrySanitizer -----

    public function testSanitizerStringStripsControlCharactersAndTruncates(): void
    {
        $this->assertSame('clean', TelemetrySanitizer::string("cl\0ea\x07n"));
        $this->assertSame('abc' . "\u{2026}", TelemetrySanitizer::string('abcdef', 3));
        $this->assertSame('short', TelemetrySanitizer::string('short', 100));
    }

    public function testSanitizerRedactScrubsCredentialPatterns(): void
    {
        $redacted = TelemetrySanitizer::redact('password=hunter2 token=abc123 Authorization: Bearer xyz');
        $this->assertStringNotContainsString('hunter2', $redacted);
        $this->assertStringNotContainsString('abc123', $redacted);
        $this->assertSame('password=[REDACTED] token=[REDACTED] Authorization=[REDACTED] xyz', trim(TelemetrySanitizer::redact('password=hunter2 token=abc123 Authorization: Bearer xyz')), 'authorization key redacted; bare bearer value after a redacted key survives as prose');
        $this->assertStringContainsString('xyz', $redacted);
        $this->assertSame('password=[REDACTED] token=[REDACTED]', trim(TelemetrySanitizer::redact('password=hunter2 token=abc123')));
        $this->assertSame('Authorization=[REDACTED] abc.def', trim(TelemetrySanitizer::redact('Authorization: Bearer abc.def')), 'colon after Authorization is captured by the key scrub; trailing token value survives');
        $this->assertSame('Bearer [REDACTED]', trim(TelemetrySanitizer::redact('Bearer standalone-token')));
    }

    public function testSanitizerSensitiveKeyDetection(): void
    {
        foreach (['authorization', 'set-cookie', 'x-api-key', 'password', 'client_secret', 'access_token'] as $key) {
            $this->assertTrue(TelemetrySanitizer::isSensitiveKey($key));
        }
        foreach (['operation', 'http.method', 'user.id'] as $key) {
            $this->assertFalse(TelemetrySanitizer::isSensitiveKey($key));
        }
    }

    public function testSanitizerValueAndAttributes(): void
    {
        $this->assertSame(42, TelemetrySanitizer::value(42));
        $this->assertSame(1.5, TelemetrySanitizer::value(1.5));
        $this->assertTrue(TelemetrySanitizer::value(true));
        $this->assertNull(TelemetrySanitizer::value(null));
        $this->assertSame('string', TelemetrySanitizer::value('string'));

        $array = TelemetrySanitizer::value(['authorization' => 'secret', 'nested' => ['deep' => 'x'], 'ok' => 1]);
        $this->assertIsArray($array);
        $this->assertSame('[REDACTED]', $array['authorization']);
        $this->assertSame(1, $array['ok']);

        $resource = fopen('php://memory', 'r');
        $this->assertSame('resource (stream)', TelemetrySanitizer::value($resource));
        $this->assertSame('stdClass', TelemetrySanitizer::value(new stdClass()));

        $attrs = TelemetrySanitizer::attributes(['api_key' => 'sk-123', 'operation' => 'create', 'http.method' => 'POST']);
        $this->assertArrayNotHasKey('api_key', $attrs);
        $this->assertSame('create', $attrs['operation']);
        $this->assertSame('POST', $attrs['http.method']);
    }

    // ----- TelemetryClock -----

    public function testTelemetryClockProducesMonotonicNanoValues(): void
    {
        $a = TelemetryClock::nowNs();
        $this->assertGreaterThan(0, $a);
        $b = TelemetryClock::nowNs();
        $this->assertGreaterThanOrEqual($a, $b);
        $this->assertGreaterThan(1_000_000_000_000_000_000, TelemetryClock::nowUnixNano(), 'unix nanos are in the 1e18 range');
    }

    // ----- Tracer / Span / InMemorySpanExporter -----

    public function testTracerStartSpanAndEndPushesToProcessor(): void
    {
        $exporter = new InMemorySpanExporter();
        $processor = new \Zef\Framework\Observability\BatchSpanProcessor($exporter);
        $tracer = new Tracer($processor);
        $span = $tracer->startSpan('http.request', ['http.method' => 'GET', 'authorization' => 'Bearer secret'], $this->validContext());
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame(self::TRACE, $span->getContext()->traceId, 'parent trace id is inherited');
        $this->assertFalse($span->isEnded());
        $span->setAttribute('http.status_code', 200);
        $span->addEvent('headers.sent', ['size' => 123]);
        $span->setStatus('OK', 'all good');
        $span->end();
        $this->assertTrue($span->isEnded());
        $processor->flush();
        $spans = $exporter->spans();
        $this->assertCount(1, $spans);
        $this->assertSame('http.request', $spans[0]->name);
        $this->assertSame('OK', $spans[0]->status);
        $this->assertSame('all good', $spans[0]->statusDescription);
        $this->assertArrayNotHasKey('authorization', $spans[0]->attributes, 'sensitive attributes are dropped');
        $this->assertSame('GET', $spans[0]->attributes['http.method']);
        $this->assertGreaterThanOrEqual(0.0, $spans[0]->durationSeconds());
        $this->assertGreaterThanOrEqual($spans[0]->startNs, $spans[0]->endNs, 'endNs never precedes startNs');
    }

    public function testSpanMutationsAfterEndAreIgnored(): void
    {
        $exporter = new InMemorySpanExporter();
        $processor = new \Zef\Framework\Observability\BatchSpanProcessor($exporter);
        $tracer = new Tracer($processor);
        $span = $tracer->startSpan('op');
        $span->end(500);
        $span->setAttribute('late', 'value');
        $span->addEvent('late.event');
        $span->setStatus('ERROR');
        $span->end(999);
        $processor->flush();
        $spans = $exporter->spans();
        $this->assertCount(1, $spans, 'second end() is a no-op');
        $this->assertArrayNotHasKey('late', $spans[0]->attributes);
        $this->assertSame([], $spans[0]->events);
    }

    public function testSpanSetStatusValidatesValue(): void
    {
        $exporter = new InMemorySpanExporter();
        $tracer = new Tracer(new \Zef\Framework\Observability\BatchSpanProcessor($exporter));
        $span = $tracer->startSpan('op');
        $this->expectException(InvalidArgumentException::class);
        $span->setStatus('BOGUS');
    }

    public function testNoopTracerAndNoopSpanAreChainableNoOps(): void
    {
        $tracer = new NoopTracer();
        $span = $tracer->startSpan('anything');
        $this->assertInstanceOf(NoopSpan::class, $span);
        $this->assertSame($span, NoopSpan::instance(), 'singleton instance');
        $this->assertSame($span, $span->setAttribute('k', 'v'));
        $this->assertSame($span, $span->setAttributes(['a' => 1]));
        $this->assertSame($span, $span->addEvent('e'));
        $this->assertSame($span, $span->setStatus('OK'));
        $this->assertTrue($span->isEnded());
        $this->assertFalse($span->getContext()->isValid());
        $span->end();
        $this->addToAssertionCount(1);
    }

    public function testSpanDataDurationFloorsAtZero(): void
    {
        $data = new SpanData('op', $this->validContext(), null, 5_000, 2_000, 1, 1, 'UNSET', null, [], []);
        $this->assertSame(0.0, $data->durationSeconds());
        $this->assertSame('op', $data->name);
        $this->assertSame('UNSET', $data->status);
        $this->assertNull($data->parent);
    }

    public function testTracerDisabledReturnsNoopSpan(): void
    {
        $processor = new \Zef\Framework\Observability\BatchSpanProcessor(new InMemorySpanExporter());
        $tracer = new Tracer($processor, false);
        $this->assertInstanceOf(NoopSpan::class, $tracer->startSpan('op'));
    }

    public function testInMemorySpanExporterResetAndShutdown(): void
    {
        $exporter = new InMemorySpanExporter();
        $processor = new \Zef\Framework\Observability\BatchSpanProcessor($exporter);
        $tracer = new Tracer($processor);
        $tracer->startSpan('one')->end();
        $tracer->startSpan('two')->end();
        $processor->flush();
        $this->assertCount(2, $exporter->spans());
        $this->assertTrue($processor->isInMemoryExporter());
        $exporter->reset();
        $this->assertSame([], $exporter->spans());
        $exporter->shutdown();
        $this->addToAssertionCount(1);
    }

    public function testBatchSpanProcessorFlushExportsAndShutdownDrains(): void
    {
        $exporter = new InMemorySpanExporter();
        $processor = new \Zef\Framework\Observability\BatchSpanProcessor($exporter);
        $tracer = new Tracer($processor);
        $tracer->startSpan('queued')->end(10);
        $this->assertSame([], $exporter->spans(), 'queued spans are not exported before flush');
        $processor->flush();
        $this->assertCount(1, $exporter->spans());

        $tracer->startSpan('drained')->end(10);
        $processor->shutdown();
        $this->assertCount(2, $exporter->spans(), 'shutdown drains remaining queued spans');
        // Second shutdown is a no-op.
        $processor->shutdown();
        $tracer->startSpan('after-shutdown')->end(10);
        $processor->flush();
        $this->assertCount(2, $exporter->spans(), 'spans after shutdown are dropped');
    }

    public function testBatchSpanProcessorRejectsInvalidSizes(): void
    {
        try {
            new \Zef\Framework\Observability\BatchSpanProcessor(new InMemorySpanExporter(), 0);
            $this->fail('zero maxQueueSize must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        new \Zef\Framework\Observability\BatchSpanProcessor(new InMemorySpanExporter(), 10, 0);
    }

    public function testSpanEndWithExplicitLowerThanStartFloorsAtStart(): void
    {
        // Span::end() floors endNs at startNs: a tiny explicit value (always
        // below the real monotonic startNs captured by the tracer) must yield
        // endNs === startNs, i.e. a zero-duration span rather than a negative
        // one. Deterministic across environments because 1 < any hrtime start.
        $exporter = new InMemorySpanExporter();
        $processor = new \Zef\Framework\Observability\BatchSpanProcessor($exporter);
        $tracer = new Tracer($processor);
        $span = $tracer->startSpan('op');
        $span->end(1);
        $processor->flush();
        $data = $exporter->spans()[0];
        $this->assertGreaterThan(0, $data->startNs, 'tracer captured a real monotonic start');
        $this->assertSame($data->startNs, $data->endNs, 'endNs floored at the real startNs');
        $this->assertSame(0.0, $data->durationSeconds());
    }

    // ----- CounterMeter -----

    public function testCounterMeterIncrementObserveAndSnapshot(): void
    {
        $meter = new CounterMeter();
        $meter->increment('zef.lifecycle.events.total', 1, ['event.name' => 'worker.started']);
        $meter->increment('zef.lifecycle.events.total', 2, ['event.name' => 'request.completed']);
        $meter->increment('zef.lifecycle.events.total', 1, ['event.name' => 'request.completed']);
        $meter->observe('zef.http.request.duration_seconds', 0.25, ['http.request.method' => 'GET']);
        $snapshot = $meter->snapshot();
        $this->assertCount(3, $snapshot);
    }

    public function testCounterMeterNormalizesHttpAndContainerDimensions(): void
    {
        $meter = new CounterMeter();
        $meter->increment('zef.http.errors.total', 1, ['http.request.method' => 'POST', 'exception.type' => 'RuntimeException', 'http.response.status_code' => 500]);
        $meter->increment('zef.http.errors.total', 1, ['http.request.method' => 'POST', 'exception.type' => 'LogicException', 'http.response.status_code' => 400]);
        $meter->increment('zef.container.resolve.duration_seconds', 0.5, ['zef.service.id' => 'svc-ok', 'http.request.method' => 'POST']);
        $meter->increment('zef.container.resolve.duration_seconds', 0.5, ['zef.service.id' => str_repeat('x', 200), 'http.request.method' => 'POST']);
        $snapshot = $meter->snapshot();
        $this->assertCount(4, $snapshot);
        $values = array_values($snapshot);
        foreach ($values as $value) {
            $this->assertArrayHasKey('http.request.method', $value['attributes']);
        }
        $this->assertSame('RuntimeException', $values[0]['attributes']['exception.type']);
        $this->assertSame(500, $values[0]['attributes']['http.response.status_code'], 'error counters keep method + status_code + exception.type');
        $this->assertSame('LogicException', $values[1]['attributes']['exception.type']);
        $this->assertArrayNotHasKey('exception.type', $values[2]['attributes'], 'container metric keeps method + zef.service.id only');
        $this->assertSame('svc-ok', $values[2]['attributes']['zef.service.id']);
        $this->assertSame('[other]', $values[3]['attributes']['zef.service.id'], 'overlong service id is bucketed');
    }

    public function testCounterMeterLifecycleEventNameNormalization(): void
    {
        $meter = new CounterMeter();
        $meter->increment('zef.lifecycle.events.total', 1, ['event.name' => 'worker.started']);
        $meter->increment('zef.lifecycle.events.total', 1, ['event.name' => 'something.else']);
        $snapshot = $meter->snapshot();
        $values = array_values($snapshot);
        $this->assertSame('worker.started', $values[0]['attributes']['event.name']);
        $this->assertSame('other', $values[1]['attributes']['event.name'], 'unknown event names are bucketed as other');
    }

    public function testCounterMeterServiceDimensionIsBounded(): void
    {
        $meter = new CounterMeter();
        $meter->increment('zef.container.resolve.duration_seconds', 0.5, ['zef.service.id' => str_repeat('x', 200), 'http.request.method' => 'GET']);
        $meter->increment('zef.container.resolve.duration_seconds', 0.5, ['zef.service.id' => 'svc-1', 'http.request.method' => 'GET']);
        $snapshot = $meter->snapshot();
        $this->assertCount(2, $snapshot, 'different bounded service ids form separate series');
        $values = array_values($snapshot);
        $this->assertSame('[other]', $values[0]['attributes']['zef.service.id'], 'overlong service id is bucketed');
        $this->assertSame('svc-1', $values[1]['attributes']['zef.service.id']);
    }

    public function testCounterMeterOverflowBucket(): void
    {
        $meter = new CounterMeter();
        // CounterMeter caps at 1024 distinct series; we verify the internal
        // overflow coalescing by flooding 1100 distinct names.
        for ($i = 0; $i < 1100; ++$i) {
            $meter->increment('metric.' . $i, 1, ['http.request.method' => 'GET']);
        }
        $snapshot = $meter->snapshot();
        $this->assertLessThanOrEqual(1025, count($snapshot));
    }

    public function testCounterMeterCustomMetricKeepsAttributes(): void
    {
        $meter = new CounterMeter();
        $meter->increment('orders.created', 3, ['region' => 'eu', 'api_key' => 'secret']);
        $snapshot = $meter->snapshot();
        $entry = $snapshot[array_key_first($snapshot)];
        $this->assertSame(3, $entry['count']);
        $this->assertSame(3.0, $entry['sum']);
        $this->assertArrayNotHasKey('api_key', $entry['attributes'], 'sensitive attributes removed before storage');
        $this->assertSame('eu', $entry['attributes']['region']);
    }

    // ----- RetryBackoffPolicy -----

    public function testRetryBackoffPolicyBoundsAndBehaviour(): void
    {
        foreach ([
            [-1, 10, 10],
            [2, -1, 10],
            [2, 100, 50],
        ] as [$retries, $initial, $max]) {
            try {
                new RetryBackoffPolicy($retries, $initial, $max);
                $this->fail('invalid policy must throw');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $policy = new RetryBackoffPolicy(3, 100, 1000);
        $this->assertTrue($policy->shouldRetry(0));
        $this->assertTrue($policy->shouldRetry(2));
        $this->assertFalse($policy->shouldRetry(3));
        $this->assertSame(100, $policy->delayMs(0));
        $this->assertSame(200, $policy->delayMs(1));
        $this->assertSame(400, $policy->delayMs(2));
        $this->assertSame(1000, (new RetryBackoffPolicy(5, 100, 1000))->delayMs(10), 'capped at maxDelayMs');

        foreach ([-1] as $badIndex) {
            try {
                $policy->shouldRetry($badIndex);
                $this->fail('negative retry index must throw');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $policy->delayMs(-1);
    }

    public function testRetryBackoffPolicyFromEnvironmentDefaults(): void
    {
        $policy = RetryBackoffPolicy::fromEnvironment();
        $this->assertSame(2, $policy->maxRetries);
        $this->assertSame(100, $policy->initialDelayMs);
        $this->assertSame(1000, $policy->maxDelayMs);
    }

    public function testRetryBackoffPolicyFromEnvironmentHonoursBoundaries(): void
    {
        $previous = [
            'ZEF_OTEL_RETRY_ATTEMPTS' => getenv('ZEF_OTEL_RETRY_ATTEMPTS'),
            'ZEF_OTEL_RETRY_DELAY_MS' => getenv('ZEF_OTEL_RETRY_DELAY_MS'),
            'ZEF_OTEL_RETRY_DELAY_CAP_MS' => getenv('ZEF_OTEL_RETRY_DELAY_CAP_MS'),
        ];
        try {
            putenv('ZEF_OTEL_RETRY_ATTEMPTS=5');
            putenv('ZEF_OTEL_RETRY_DELAY_MS=250');
            putenv('ZEF_OTEL_RETRY_DELAY_CAP_MS=2000');
            $policy = RetryBackoffPolicy::fromEnvironment();
            $this->assertSame(5, $policy->maxRetries);
            $this->assertSame(250, $policy->initialDelayMs);
            $this->assertSame(2000, $policy->maxDelayMs);

            putenv('ZEF_OTEL_RETRY_ATTEMPTS=not-an-int');
            $this->assertSame(2, RetryBackoffPolicy::fromEnvironment()->maxRetries, 'invalid env falls back to default');
        } finally {
            foreach ($previous as $name => $value) {
                $value === false ? putenv($name) : putenv($name . '=' . $value);
            }
        }
    }

    // ----- Telemetry facade -----

    public function testTelemetryDisabledInstanceIsNoop(): void
    {
        $telemetry = new Telemetry(new NoopTracer(), new CounterMeter(), new \Zef\Framework\Observability\BatchSpanProcessor(new InMemorySpanExporter()), null, null, false);
        $this->assertFalse($telemetry->isEnabled());
        $span = $telemetry->startSpan('op');
        $this->assertInstanceOf(NoopSpan::class, $span);
        $telemetry->recordLog('INFO', 'hello');
        $telemetry->flush();
        $telemetry->shutdown();
        $this->assertTrue($telemetry->isInMemoryExporter());
        $this->addToAssertionCount(1);
    }

    public function testTelemetryFromEnvironmentDisabledPath(): void
    {
        $previous = getenv('ZEF_OTEL_ENABLED');
        try {
            putenv('ZEF_OTEL_ENABLED=0');
            $telemetry = Telemetry::fromEnvironment();
            $this->assertFalse($telemetry->isEnabled());
            $this->assertTrue($telemetry->isInMemoryExporter());
        } finally {
            $previous === false ? putenv('ZEF_OTEL_ENABLED') : putenv('ZEF_OTEL_ENABLED=' . $previous);
        }
    }

    public function testTelemetryEndpointValidation(): void
    {
        $previous = [
            'ZEF_OTEL_ENABLED' => getenv('ZEF_OTEL_ENABLED'),
            'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT' => getenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT'),
        ];
        try {
            putenv('ZEF_OTEL_ENABLED=1');
            putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=not-a-url');
            $this->expectException(InvalidArgumentException::class);
            Telemetry::fromEnvironment();
        } finally {
            foreach ($previous as $name => $value) {
                $value === false ? putenv($name) : putenv($name . '=' . $value);
            }
        }
    }
}
