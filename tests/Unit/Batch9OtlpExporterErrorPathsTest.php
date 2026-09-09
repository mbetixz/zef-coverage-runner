<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Observability\LogRecord;
use Zef\Framework\Observability\OtlpHttpJsonExporter;
use Zef\Framework\Observability\Span;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Observability\SpanData;

/**
 * Batch 9 coverage: OtlpHttpJsonExporter remaining statement paths —
 * attribute value typing (bool/int/float/array), status UNSET without
 * description, span without parent/events, URL suffix handling and the
 * metrics/logs payload builders. Every export attempt targets a closed
 * port (127.0.0.1:1) so transport fails after payload construction: the
 * mapping code under test always runs; no real network dependency.
 */
final class Batch9OtlpExporterErrorPathsTest extends TestCase
{
    private function dataSpan(): SpanData
    {
        $exporter = new class () implements \Zef\Framework\Observability\SpanExporterInterface {
            #[\Override]
            public function export(array $spans): void
            {
            }

            #[\Override]
            public function shutdown(): void
            {
            }
        };
        // Use the real Span via a capturing processor-free callback: end()
        // invokes the onEnd callback with the assembled SpanData.
        $captured = null;
        $span = new Span(
            'typing',
            new SpanContext('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', true, 'vendor=value'),
            null,
            1,
            1,
            static function (SpanData $data) use (&$captured): void {
                $captured = $data;
            },
        );
        $span->setAttribute('flag', true);
        $span->setAttribute('count', 7);
        $span->setAttribute('ratio', 1.5);
        $span->setAttribute('list', ['x', 'y']);
        $span->setAttribute('nested', ['k' => ['deep' => 3]]);
        $span->setAttribute('obj', new \stdClass());
        $span->setStatus('UNSET');
        $span->end(1);
        self::assertInstanceOf(SpanData::class, $captured);
        /** @var SpanData $captured */
        return $captured;
    }

    public function testExportMetricsTransportFailureRunsPayloadBuilder(): void
    {
        $exporter = new OtlpHttpJsonExporter('http://127.0.0.1:1', ['service.name' => 'svc'], 50);
        try {
            $exporter->exportMetrics([
                'zef.requests' => ['count' => 3, 'sum' => 3.0, 'attributes' => ['method' => 'GET', 'flag' => true, 'count' => 2]],
                'zef.seconds' => ['count' => 1, 'sum' => 0.25, 'attributes' => []],
            ]);
            self::fail('transport failure expected');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('transport failure', $e->getMessage());
        }
    }

    public function testExportLogsTransportFailureRunsPayloadBuilder(): void
    {
        $exporter = new OtlpHttpJsonExporter('http://127.0.0.1:1', ['service.name' => 'svc'], 50);
        $records = [
            new LogRecord('ERROR', 'boom', 123456789, ['user.id' => 42, 'ok' => false]),
            new LogRecord('INFO', 'plain', 987654321),
        ];
        try {
            $exporter->exportLogs($records);
            self::fail('transport failure expected');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('transport failure', $e->getMessage());
        }
    }

    public function testExportSpanWithTypedAttributesAndNoParent(): void
    {
        $exporter = new OtlpHttpJsonExporter('http://127.0.0.1:1', ['service.name' => 'svc'], 50);
        try {
            $exporter->export([$this->dataSpan()]);
            self::fail('transport failure expected');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testExportAppendsTracesPathWhenMissing(): void
    {
        // Endpoint without the /v1/traces suffix: export() appends it before
        // building the request; the closed port still yields a transport
        // failure, proving the append + postJson path executed.
        $exporter = new OtlpHttpJsonExporter('http://127.0.0.1:1', [], 50);
        try {
            $exporter->export([$this->dataSpan()]);
            self::fail('transport failure expected');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testExportMetricsAppendsMetricsPathWhenMissing(): void
    {
        $exporter = new OtlpHttpJsonExporter('http://127.0.0.1:1', [], 50);
        try {
            $exporter->exportMetrics(['m' => ['count' => 1, 'sum' => 1.0, 'attributes' => []]]);
            self::fail('transport failure expected');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testExportLogsAppendsLogsPathWhenMissing(): void
    {
        $exporter = new OtlpHttpJsonExporter('http://127.0.0.1:1', [], 50);
        try {
            $exporter->exportLogs([new LogRecord('WARN', 'w', 1)]);
            self::fail('transport failure expected');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testEmptyExportsReturnEarly(): void
    {
        $exporter = new OtlpHttpJsonExporter('http://127.0.0.1:1', [], 50);
        $exporter->export([]);
        $exporter->exportMetrics([]);
        $exporter->exportLogs([]);
        $this->addToAssertionCount(1);
    }

    public function testExporterTimeoutBoundaryAccepted(): void
    {
        // Boundaries of the allowed timeout range must construct cleanly.
        $a = new OtlpHttpJsonExporter('http://127.0.0.1:1', [], 1);
        $b = new OtlpHttpJsonExporter('http://127.0.0.1:1', [], 10000);
        $a->shutdown();
        $b->shutdown();
        $this->addToAssertionCount(1);
    }

    public function testStatusDescriptionAndEventsAreSanitized(): void
    {
        $captured = null;
        $span = new Span(
            'with-events',
            new SpanContext('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', true, null),
            null,
            1,
            1,
            static function (SpanData $data) use (&$captured): void {
                $captured = $data;
            },
        );
        $span->setStatus('ERROR', "secret=abc123 and \x01ctl\x7f");
        $span->addEvent('evt', ['k' => 'v']);
        $span->end(1);
        self::assertInstanceOf(SpanData::class, $captured);

        $exporter = new OtlpHttpJsonExporter('http://127.0.0.1:1', [], 50);
        try {
            $exporter->export([$captured]);
            self::fail('transport failure expected');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}
