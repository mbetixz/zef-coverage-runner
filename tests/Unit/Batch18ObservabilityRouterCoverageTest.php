<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;
    use Zef\Framework\Observability\BatchSpanProcessor;
    use Zef\Framework\Observability\CorrelationContext;
    use Zef\Framework\Observability\CounterMeter;
    use Zef\Framework\Observability\InMemorySpanExporter;
    use Zef\Framework\Observability\Span;
    use Zef\Framework\Observability\SpanContext;
    use Zef\Framework\Observability\SpanData;
    use Zef\Framework\Observability\Telemetry;

    /**
     * Batch 18 coverage: statement paths in src/Framework/Observability that
     * the existing suites (Batch6ObservabilityTest, Batch8TelemetryOtlpEdgeTest,
     * Batch9TelemetryLoggerTest, Batch9OtlpExporterErrorPathsTest) do not
     * execute:
     *
     *  - CounterMeter::increment()/observe() cardinality-overflow bucket
     *    (CounterMeter.php L33-38) when the 1025th distinct series arrives.
     *  - BatchSpanProcessor::shutdown() with a queue and a throwing exporter
     *    (BatchSpanProcessor.php L58-61) and the shutdown() swallow path
     *    (L64-67); plus flush() batching over a queue (L46-53).
     *  - CorrelationContext oversized-tracestate propagation guard
     *    (CorrelationContext.php L40).
     *  - Telemetry::flush() drain loops with a metric exporter and with no
     *    log exporter (Telemetry.php L100-108) and shutdown() with distinct
     *    metric/log exporters (L122-129).
     *
     * Determinism: exporters are in-memory doubles (no network), retry policy
     * sleeps are avoided by never entering the retry loop, and clock reads are
     * not asserted. Supply chain: no new dependencies; tests/Regression/
     * untouched.
     */
    final class Batch18ObservabilityRouterCoverageTest extends TestCase
    {
        private function validSpanContext(string $seed): SpanContext
        {
            return new SpanContext(str_repeat($seed, 32), str_repeat('f', 16), true, null);
        }

        // ---------------------------------------------------------------- //
        // CounterMeter cardinality overflow (L33-38)
        // ---------------------------------------------------------------- //

        public function testIncrementOverflowBucketAfter1024Series(): void
        {
            $meter = new CounterMeter();
            for ($i = 0; $i < 1024; ++$i) {
                $meter->increment('series.' . $i, 1);
            }
            // 1025th distinct series: no slot left, must go to the overflow
            // bucket (CounterMeter.php L33-38).
            $meter->increment('series.overflow', 1);
            $snapshot = $meter->snapshot();
            $overflow = $snapshot['series.overflow|{"zef.cardinality.bucket":"overflow"}'] ?? null;
            self::assertNotNull($overflow, 'overflow bucket must be present');
            self::assertCount(1024, $snapshot, 'one series is evicted to make room for the overflow bucket');
        }

        public function testObserveOverflowBucketAfter1024Series(): void
        {
            $meter = new CounterMeter();
            for ($i = 0; $i < 1024; ++$i) {
                $meter->observe('gauge.' . $i, (float) $i);
            }
            $meter->observe('gauge.overflow', 7.5);
            $snapshot = $meter->snapshot();
            $overflow = $snapshot['gauge.overflow|{"zef.cardinality.bucket":"overflow"}'] ?? null;
            self::assertNotNull($overflow, 'overflow bucket must be present');
            self::assertSame(1, $overflow['count']);
            self::assertSame(7.5, $overflow['sum']);
        }

        // ---------------------------------------------------------------- //
        // BatchSpanProcessor flush batching + shutdown swallow
        // ---------------------------------------------------------------- //

        public function testFlushExportsQueuedSpansInBatches(): void
        {
            $exporter = new class () implements \Zef\Framework\Observability\SpanExporterInterface {
                /** @var list<SpanData> */
                private array $exported = [];

                #[Override]
                public function export(array $spans): void
                {
                    foreach ($spans as $span) {
                        $this->exported[] = $span;
                    }
                }

                #[Override]
                public function shutdown(): void
                {
                }

                /** @return list<SpanData> */
                public function exported(): array
                {
                    return $this->exported;
                }
            };
            $processor = new BatchSpanProcessor($exporter, 10, 2);
            foreach (['a', 'b', 'c'] as $seed) {
                $span = new Span(
                    'span-' . $seed,
                    $this->validSpanContext($seed),
                    null,
                    1,
                    1,
                    $processor->onEnd(...),
                );
                $span->end(1);
            }
            $processor->flush();
            self::assertCount(3, $exporter->exported(), 'all queued spans must be exported');
        }

        public function testShutdownSwallowsExporterFailures(): void
        {
            $exporter = new class () implements \Zef\Framework\Observability\SpanExporterInterface {
                #[Override]
                public function export(array $spans): void
                {
                    throw new \RuntimeException('export boom');
                }

                #[Override]
                public function shutdown(): void
                {
                    throw new \RuntimeException('shutdown boom');
                }
            };
            $processor = new BatchSpanProcessor($exporter, 10, 5);
            $span = new Span(
                'boom',
                $this->validSpanContext('b'),
                null,
                1,
                1,
                $processor->onEnd(...),
            );
            $span->end(1);
            // The drain export (L58-61) and final shutdown (L64-67) both throw
            // and both must be swallowed.
            $processor->shutdown();
            $this->addToAssertionCount(1);
        }

        // ---------------------------------------------------------------- //
        // CorrelationContext propagation-size guard (L40)
        // ---------------------------------------------------------------- //

        public function testCorrelationContextRejectsOversizedTraceState(): void
        {
            try {
                new CorrelationContext(
                    str_repeat('a', 32),
                    str_repeat('b', 16),
                    '01',
                    str_repeat('k=v,', 300),
                    'op-1',
                );
                self::fail('oversized tracestate must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('tracestate', $e->getMessage());
            }
        }

        public function testCorrelationContextAcceptsFloatAttribute(): void
        {
            // A float attribute exercises scalarByteLength()'s float branch
            // (CorrelationContext.php L172-173).
            $context = new CorrelationContext(
                str_repeat('a', 32),
                str_repeat('b', 16),
                '01',
                null,
                'op-1',
                null,
                ['ratio' => 1.5],
            );
            self::assertSame(1.5, $context->attributes['ratio']);
        }

        // ---------------------------------------------------------------- //
        // Telemetry drain loops
        // ---------------------------------------------------------------- //

        public function testFlushDrainsMetricsThroughMetricExporter(): void
        {
            $meter = new CounterMeter();
            $processor = new BatchSpanProcessor(new InMemorySpanExporter());
            $metricExporter = new class () implements \Zef\Framework\Observability\MetricExporterInterface {
                /** @var list<array<string,mixed>> */
                private array $sink = [];

                #[Override]
                public function exportMetrics(array $metrics): void
                {
                    $this->sink[] = $metrics;
                }

                #[Override]
                public function shutdown(): void
                {
                }

                /** @return list<array<string,mixed>> */
                public function sink(): array
                {
                    return $this->sink;
                }
            };
            $telemetry = new Telemetry(
                new \Zef\Framework\Observability\NoopTracer(),
                $meter,
                $processor,
                $metricExporter,
                null,
                true,
            );
            $meter->increment('zef.custom.metric', 1, ['dim' => 'a']);
            $telemetry->flush();
            self::assertCount(1, $metricExporter->sink(), 'metric snapshot must reach the exporter on flush');
            $telemetry->shutdown();
        }

        public function testShutdownWithDistinctMetricAndLogExporters(): void
        {
            $meter = new CounterMeter();
            $processor = new BatchSpanProcessor(new InMemorySpanExporter());
            $metric = new class () implements \Zef\Framework\Observability\MetricExporterInterface {
                /** @var list<string> */
                private array $calls = [];

                #[Override]
                public function exportMetrics(array $metrics): void
                {
                    $this->calls[] = 'metric.export';
                }

                #[Override]
                public function shutdown(): void
                {
                    $this->calls[] = 'metric.shutdown';
                }

                /** @return list<string> */
                public function calls(): array
                {
                    return $this->calls;
                }
            };
            $log = new class () implements \Zef\Framework\Observability\LogExporterInterface {
                /** @var list<string> */
                private array $calls = [];

                #[Override]
                public function exportLogs(array $records): void
                {
                    $this->calls[] = 'log.export';
                }

                #[Override]
                public function shutdown(): void
                {
                    $this->calls[] = 'log.shutdown';
                }

                /** @return list<string> */
                public function calls(): array
                {
                    return $this->calls;
                }
            };
            $telemetry = new Telemetry(
                new \Zef\Framework\Observability\NoopTracer(),
                $meter,
                $processor,
                $metric,
                $log,
                true,
            );
            $meter->increment('zef.custom.metric', 1, ['dim' => 'b']);
            $telemetry->recordLog('WARN', 'shutdown-log');
            $telemetry->shutdown();
            self::assertContains('metric.shutdown', $metric->calls());
            self::assertContains('log.shutdown', $log->calls());
        }
    }
}
