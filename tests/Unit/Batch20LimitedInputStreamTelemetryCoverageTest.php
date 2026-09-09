<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Zef\Framework\Http\LimitedInputStream;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\CounterMeter;
use Zef\Framework\Observability\InMemorySpanExporter;
use Zef\Framework\Observability\LogExporterInterface;
use Zef\Framework\Observability\MetricExporterInterface;
use Zef\Framework\Observability\NoopTracer;
use Zef\Framework\Observability\Telemetry;

/**
 * Batch 20 coverage: statement paths not executed by existing suites, covered
 * with pure PHPUnit mocks/doubles (NO production change):
 *
 *  - LimitedInputStream::__toString() generic Throwable catch (L19-20): an
 *    inner stream whose read() raises a non-PayloadTooLarge exception must be
 *    swallowed and produce ''.
 *  - LimitedInputStream::close() delegation to the inner stream (L26).
 *  - LimitedInputStream::getContents() break-on-empty-chunk (L106): an inner
 *    stream that reports not-EOF yet returns '' must terminate the loop.
 *  - Telemetry::shutdown() swallow of a throwing metricExporter->shutdown()
 *    (L123) and of a throwing distinct logExporter->shutdown() (L128).
 *
 * Determinism: in-memory doubles only; no network, no sleep, no wall-clock
 * assertions; the shutdown drain deadline is never approached because no
 * payloads are enqueued. tests/Regression/ untouched; no new dependencies;
 * production code unchanged.
 */
final class Batch20LimitedInputStreamTelemetryCoverageTest extends TestCase
{
    public function testToStringSwallowsGenericInnerReadException(): void
    {
        $inner = $this->createMock(StreamInterface::class);
        $inner->method('eof')->willReturn(false);
        $inner->method('read')->willThrowException(new \RuntimeException('io exploded'));

        $limited = new LimitedInputStream($inner, new RequestBodyPolicy(10));

        self::assertSame('', (string) $limited);
    }

    public function testCloseDelegatesToInnerStream(): void
    {
        $inner = $this->createMock(StreamInterface::class);
        $closed = false;
        $inner->method('close')->willReturnCallback(static function () use (&$closed): void {
            $closed = true;
        });

        $limited = new LimitedInputStream($inner, new RequestBodyPolicy(10));
        $limited->close();

        self::assertTrue($closed);
    }

    public function testGetContentsBreaksOnEmptyChunk(): void
    {
        // eof() reports false so the read loop would otherwise spin, but
        // read() returns '' → getContents() must take the break-on-empty
        // path (L106) instead of looping or appending.
        $inner = $this->createMock(StreamInterface::class);
        $inner->method('eof')->willReturn(false);
        $inner->method('read')->willReturn('');

        $limited = new LimitedInputStream($inner, new RequestBodyPolicy(10));

        self::assertSame('', $limited->getContents());
    }

    public function testShutdownSwallowsThrowingMetricAndLogExporterShutdown(): void
    {
        $meter = new CounterMeter();
        $processor = new BatchSpanProcessor(new InMemorySpanExporter());
        $metricCalls = 0;
        $logCalls = 0;
        $metricExporter = $this->throwingMetricExporter($metricCalls);
        $logExporter = $this->throwingLogExporter($logCalls);
        $telemetry = new Telemetry(new NoopTracer(), $meter, $processor, $metricExporter, $logExporter, true);

        // No payloads are enqueued (flush() is never called), so shutdown()
        // skips the drain loops and reaches the exporter shutdown swallow
        // paths: metricExporter->shutdown() throws (L122-123) and the distinct
        // logExporter->shutdown() throws (L125-128). Both must be swallowed
        // and shutdown() must complete.
        $telemetry->shutdown();

        self::assertSame(1, $metricCalls);
        self::assertSame(1, $logCalls);
        self::assertTrue($telemetry->isEnabled());
    }

    private function throwingMetricExporter(int &$calls): MetricExporterInterface
    {
        return new class ($calls) implements MetricExporterInterface {
            public function __construct(private int &$calls)
            {
            }

            #[Override]
            public function exportMetrics(array $metrics): void
            {
            }

            #[Override]
            public function shutdown(): void
            {
                ++$this->calls;
                throw new \RuntimeException('metric shutdown boom');
            }
        };
    }

    private function throwingLogExporter(int &$calls): LogExporterInterface
    {
        return new class ($calls) implements LogExporterInterface {
            public function __construct(private int &$calls)
            {
            }

            #[Override]
            public function exportLogs(array $records): void
            {
            }

            #[Override]
            public function shutdown(): void
            {
                ++$this->calls;
                throw new \RuntimeException('log shutdown boom');
            }
        };
    }
}
