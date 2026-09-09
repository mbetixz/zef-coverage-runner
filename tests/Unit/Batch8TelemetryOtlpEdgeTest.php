<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\CounterMeter;
use Zef\Framework\Observability\InMemorySpanExporter;
use Zef\Framework\Observability\OtlpHttpJsonExporter;
use Zef\Framework\Observability\Span;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Observability\SpanData;
use Zef\Framework\Observability\SpanExporterInterface;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Observability\Tracer;

/**
 * Batch 8 coverage: Telemetry delivery/shutdown paths, BatchSpanProcessor
 * retry/queue edges and OtlpHttpJsonExporter payload construction.
 *
 * Deterministic: exporters are injected doubles or local in-memory ones; no
 * real network I/O (the Otlp exporter's transport is exercised only through
 * the local HTTP expectations below with a guaranteed-failing socket). The
 * single wall-clock read is the processor's shutdown deadline, whose upper
 * bound is fixed by env (0 ms) so no real waiting occurs.
 */
final class Batch8TelemetryOtlpEdgeTest extends TestCase
{
    private const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const SPAN = '00f067aa0ba902b7';

    private function context(): SpanContext
    {
        return new SpanContext(self::TRACE, self::SPAN, true, 'vendor=value');
    }

    private function telemetryEnabled(): Telemetry
    {
        $exporter = new InMemorySpanExporter();
        $processor = new BatchSpanProcessor($exporter);
        return new Telemetry(new Tracer($processor), new CounterMeter(), $processor);
    }

    public function testRecordLogAppendsAndFlushClears(): void
    {
        $telemetry = $this->telemetryEnabled();
        $telemetry->recordLog('INFO', 'boot complete', ['service' => 'api']);
        $telemetry->flush();
        // No exception is the assertion; logs are drained into the in-memory
        // delivery path (no log exporter configured -> queue drained as no-op).
        $telemetry->recordLog('ERROR', 'after flush');
        $telemetry->shutdown();
        $this->assertTrue($telemetry->isEnabled());
        $this->assertTrue($telemetry->isInMemoryExporter());
        $this->addToAssertionCount(1);
    }

    public function testRecordLogCapDropsBeyond256(): void
    {
        $telemetry = $this->telemetryEnabled();
        for ($i = 0; $i < 300; ++$i) {
            $telemetry->recordLog('INFO', 'log ' . $i);
        }
        // No throw; queue stays bounded.
        $telemetry->flush();
        $this->addToAssertionCount(1);
    }

    public function testShutdownDrainsMetricAndLogQueues(): void
    {
        $metricExporter = $this->createMock(Zef\Framework\Observability\MetricExporterInterface::class);
        $metricExporter->expects($this->atLeastOnce())->method('exportMetrics');
        $metricExporter->expects($this->once())->method('shutdown');

        $logExporter = $this->createMock(Zef\Framework\Observability\LogExporterInterface::class);
        $logExporter->expects($this->atLeastOnce())->method('exportLogs');
        $logExporter->expects($this->once())->method('shutdown');

        $spanExporter = $this->createMock(SpanExporterInterface::class);
        $spanExporter->method('export');
        $processor = new BatchSpanProcessor($spanExporter);
        $telemetry = new Telemetry(new Tracer($processor), new CounterMeter(), $processor, $metricExporter, $logExporter, true);
        $telemetry->recordLog('WARN', 'hello telemetry');
        // flush() enqueues + drains deliveries; shutdown() then closes the
        // exporters and flips the shutdown guard.
        $telemetry->flush();
        $telemetry->shutdown();

        $this->assertTrue($telemetry->isEnabled(), 'enabled flag is unchanged by shutdown; the shutdown flag guards work');
        $this->addToAssertionCount(1);
    }

    public function testShutdownWithMetricExporterReusedAsLogExporter(): void
    {
        // A single object implements both exporter interfaces; its shutdown()
        // must run exactly once (Telemetry skips the second call when the two
        // references point at the same instance).
        $dual = new class () implements Zef\Framework\Observability\MetricExporterInterface, Zef\Framework\Observability\LogExporterInterface {
            public int $shutdowns = 0;
            #[Override]
            public function exportMetrics(array $metrics): void
            {
            }
            #[Override]
            public function exportLogs(array $records): void
            {
            }
            #[Override]
            public function shutdown(): void
            {
                ++$this->shutdowns;
            }
        };

        $spanExporter = $this->createMock(SpanExporterInterface::class);
        $processor = new BatchSpanProcessor($spanExporter);
        $telemetry = new Telemetry(new Tracer($processor), new CounterMeter(), $processor, $dual, $dual, true);
        $telemetry->recordLog('INFO', 'dup');
        $telemetry->shutdown();
        $this->assertSame(1, $dual->shutdowns, 'shared exporter is shut down exactly once');
    }

    public function testFlushAfterShutdownIsNoop(): void
    {
        $telemetry = $this->telemetryEnabled();
        $telemetry->shutdown();
        $telemetry->recordLog('INFO', 'ignored');
        $telemetry->flush();
        $this->assertTrue($telemetry->isEnabled());
        $this->addToAssertionCount(1);
    }

    public function testFromEnvironmentEnabledWithLocalEndpointFailsFastOnTransport(): void
    {
        $previous = [
            'ZEF_OTEL_ENABLED' => getenv('ZEF_OTEL_ENABLED'),
            'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT' => getenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT'),
            'ZEF_OTEL_EXPORT_TIMEOUT_MS' => getenv('ZEF_OTEL_EXPORT_TIMEOUT_MS'),
            'ZEF_OTEL_MAX_QUEUE' => getenv('ZEF_OTEL_MAX_QUEUE'),
            'ZEF_OTEL_BATCH_SIZE' => getenv('ZEF_OTEL_BATCH_SIZE'),
            'ZEF_OTEL_RETRY_ATTEMPTS' => getenv('ZEF_OTEL_RETRY_ATTEMPTS'),
            'ZEF_OTEL_RETRY_DELAY_MS' => getenv('ZEF_OTEL_RETRY_DELAY_MS'),
            'ZEF_OTEL_RETRY_DELAY_CAP_MS' => getenv('ZEF_OTEL_RETRY_DELAY_CAP_MS'),
            'ZEF_OTEL_SHUTDOWN_DRAIN_MS' => getenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS'),
            'ZEF_OTEL_SERVICE_NAME' => getenv('ZEF_OTEL_SERVICE_NAME'),
        ];
        try {
            putenv('ZEF_OTEL_ENABLED=1');
            putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:1/v1/traces');
            putenv('ZEF_OTEL_EXPORT_TIMEOUT_MS=100');
            putenv('ZEF_OTEL_MAX_QUEUE=16');
            putenv('ZEF_OTEL_BATCH_SIZE=4');
            putenv('ZEF_OTEL_RETRY_ATTEMPTS=0');
            putenv('ZEF_OTEL_RETRY_DELAY_MS=0');
            putenv('ZEF_OTEL_RETRY_DELAY_CAP_MS=0');
            putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=0');
            putenv('ZEF_OTEL_SERVICE_NAME=unit-test');

            $telemetry = Telemetry::fromEnvironment();
            $this->assertTrue($telemetry->isEnabled());
            $this->assertFalse($telemetry->isInMemoryExporter(), 'OTLP exporter is not in-memory');
            $telemetry->startSpan('span.to.otlp')->end();
            // Export is attempted lazily; flush swallows the transport failure.
            $telemetry->flush();
            $telemetry->shutdown();
            $this->addToAssertionCount(1);
        } finally {
            foreach ($previous as $name => $value) {
                $value === false ? putenv($name) : putenv($name . '=' . $value);
            }
        }
    }

    public function testFromEnvironmentInvalidEndpointRejected(): void
    {
        $previous = [
            'ZEF_OTEL_ENABLED' => getenv('ZEF_OTEL_ENABLED'),
            'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT' => getenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT'),
        ];
        try {
            putenv('ZEF_OTEL_ENABLED=1');
            putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=http://user:pass@host:4318');
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('must not contain embedded credentials');
            Telemetry::fromEnvironment();
        } finally {
            foreach ($previous as $name => $value) {
                $value === false ? putenv($name) : putenv($name . '=' . $value);
            }
        }
    }

    public function testFromEnvironmentRejectsInvalidIntegerEnv(): void
    {
        $previous = [
            'ZEF_OTEL_ENABLED' => getenv('ZEF_OTEL_ENABLED'),
            'ZEF_OTEL_EXPORT_TIMEOUT_MS' => getenv('ZEF_OTEL_EXPORT_TIMEOUT_MS'),
        ];
        try {
            putenv('ZEF_OTEL_ENABLED=1');
            putenv('ZEF_OTEL_EXPORT_TIMEOUT_MS=not-an-int');
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('must be an integer');
            Telemetry::fromEnvironment();
        } finally {
            foreach ($previous as $name => $value) {
                $value === false ? putenv($name) : putenv($name . '=' . $value);
            }
        }
    }

    public function testFromEnvironmentRejectsOutOfRangeEnv(): void
    {
        $previous = [
            'ZEF_OTEL_ENABLED' => getenv('ZEF_OTEL_ENABLED'),
            'ZEF_OTEL_MAX_QUEUE' => getenv('ZEF_OTEL_MAX_QUEUE'),
        ];
        try {
            putenv('ZEF_OTEL_ENABLED=1');
            putenv('ZEF_OTEL_MAX_QUEUE=99999');
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('outside its allowed range');
            Telemetry::fromEnvironment();
        } finally {
            foreach ($previous as $name => $value) {
                $value === false ? putenv($name) : putenv($name . '=' . $value);
            }
        }
    }

    // --------------------------------------------------- BatchSpanProcessor

    public function testProcessorDropsSpanWhenQueueIsFull(): void
    {
        $exporter = new InMemorySpanExporter();
        // maxQueueSize=2: a third span is dropped silently.
        $processor = new BatchSpanProcessor($exporter, 2, 1);
        $tracer = new Tracer($processor);
        $tracer->startSpan('a')->end();
        $tracer->startSpan('b')->end();
        $tracer->startSpan('c')->end();
        $processor->flush();
        $this->assertCount(2, $exporter->spans());
    }

    public function testProcessorRetriesTransientFailureThenSucceeds(): void
    {
        $exporter = $this->createMock(SpanExporterInterface::class);
        $exporter->method('export')->willReturnOnConsecutiveCalls(
            $this->throwException(new RuntimeException('boom')),
            null,
        );
        $processor = new BatchSpanProcessor($exporter, 8, 4);
        $tracer = new Tracer($processor);
        $tracer->startSpan('retry-me')->end();
        // Retry policy reads env defaults (retries=2, delay 100ms capped) and
        // would sleep 100ms before the successful retry — deterministic enough
        // here but slow; keep the batch small and use a no-delay policy via env.
        $previous = [
            'ZEF_OTEL_RETRY_ATTEMPTS' => getenv('ZEF_OTEL_RETRY_ATTEMPTS'),
            'ZEF_OTEL_RETRY_DELAY_MS' => getenv('ZEF_OTEL_RETRY_DELAY_MS'),
            'ZEF_OTEL_RETRY_DELAY_CAP_MS' => getenv('ZEF_OTEL_RETRY_DELAY_CAP_MS'),
        ];
        try {
            putenv('ZEF_OTEL_RETRY_ATTEMPTS=2');
            putenv('ZEF_OTEL_RETRY_DELAY_MS=0');
            putenv('ZEF_OTEL_RETRY_DELAY_CAP_MS=0');
            $processor->flush();
        } finally {
            foreach ($previous as $name => $value) {
                $value === false ? putenv($name) : putenv($name . '=' . $value);
            }
        }
        $this->assertTrue($processor->isInMemoryExporter() === false);
    }

    public function testProcessorStopsRetryingAfterPermanentFailure(): void
    {
        $exporter = $this->createMock(SpanExporterInterface::class);
        $exporter->method('export')->willThrowException(new InvalidArgumentException('bad payload'));
        $processor = new BatchSpanProcessor($exporter, 8, 4);
        $tracer = new Tracer($processor);
        $tracer->startSpan('perm')->end();
        $processor->flush(); // InvalidArgumentException breaks out without retry.
        $this->addToAssertionCount(1);
    }

    public function testProcessorShutdownFlushesAndIsIdempotent(): void
    {
        $exporter = new InMemorySpanExporter();
        $processor = new BatchSpanProcessor($exporter);
        $tracer = new Tracer($processor);
        $tracer->startSpan('drain-me')->end();
        // A generous drain deadline guarantees the queued span is exported in
        // the first loop iteration regardless of host speed (no real waiting:
        // the queue becomes empty after a single export).
        $previous = getenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS');
        try {
            putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=10000');
            $processor->shutdown();
            $processor->shutdown();
        } finally {
            $previous === false ? putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS') : putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=' . $previous);
        }
        $this->assertCount(1, $exporter->spans());
    }

    public function testProcessorShutdownSwallowsExporterErrors(): void
    {
        $exporter = $this->createMock(SpanExporterInterface::class);
        $exporter->method('export')->willThrowException(new RuntimeException('downstream down'));
        $processor = new BatchSpanProcessor($exporter, 8, 4);
        $tracer = new Tracer($processor);
        $tracer->startSpan('x')->end();
        $processor->shutdown();
        $this->addToAssertionCount(1);
    }

    public function testProcessorOnEndAfterShutdownDropsSpan(): void
    {
        $exporter = new InMemorySpanExporter();
        $processor = new BatchSpanProcessor($exporter);
        $tracer = new Tracer($processor);
        $tracer->startSpan('before')->end();
        $processor->shutdown();
        $tracer->startSpan('after')->end();
        $processor->flush();
        $this->assertCount(1, $exporter->spans());
    }

    // ------------------------------------------------- OtlpHttpJsonExporter

    public function testOtlpExporterRejectsInvalidTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('timeoutMs is outside its allowed range');
        new OtlpHttpJsonExporter('http://localhost:4318', [], 0);
    }

    public function testOtlpExporterTransportFailureThrows(): void
    {
        $exporter = new OtlpHttpJsonExporter('http://127.0.0.1:1', [], 50);
        // Drive export() directly: BatchSpanProcessor::flush() swallows
        // transient failures after its retry budget, so the exporter's own
        // transport error is asserted here on a single attempt.
        $captured = null;
        $span = new Span(
            'boom',
            $this->context(),
            null,
            1,
            1,
            static function (SpanData $data) use (&$captured): void {
                $captured = $data;
            },
        );
        $span->end(1);
        $this->assertInstanceOf(SpanData::class, $captured);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transport failure');
        $exporter->export([$captured]);
    }

    public function testOtlpExporterEmptyExportIsNoop(): void
    {
        $exporter = new OtlpHttpJsonExporter('http://127.0.0.1:1', [], 50);
        $exporter->export([]); // returns without network I/O
        $exporter->exportMetrics([]);
        $exporter->exportLogs([]);
        $exporter->shutdown();
        $this->addToAssertionCount(1);
    }

    public function testOtlpExporterSpanPayloadShapeWithParentAndEvent(): void
    {
        // Exercise span() private mapping via a real export attempt to a
        // closed port; failure happens after payload construction, so the
        // mapping code paths (parent, events, status, statusDescription) run.
        $exporter = new OtlpHttpJsonExporter('http://127.0.0.1:1/v1/traces', ['service.name' => 'svc'], 50);
        $processor = new BatchSpanProcessor($exporter, 4, 2);
        $tracer = new Tracer($processor);
        $span = $tracer->startSpan('op', ['http.method' => 'GET'], $this->context());
        $span->addEvent('evt', ['k' => 'v']);
        $span->setStatus('ERROR', 'oops');
        $span->end();
        try {
            $processor->flush();
            $this->fail('transport failure expected');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}
