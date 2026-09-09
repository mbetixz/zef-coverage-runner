<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    final class BatchSpanProcessor
    {
        /** @var list<SpanData> */ private array $queue = [];
        private bool $shutdown = false;
        public function __construct(private readonly SpanExporterInterface $exporter, private readonly int $maxQueueSize = 2048, private readonly int $batchSize = 256)
        {
            if ($maxQueueSize < 1) {
                throw new \InvalidArgumentException('maxQueueSize must be >= 1.');
            } if ($batchSize < 1) {
                throw new \InvalidArgumentException('batchSize must be >= 1.');
            }
        }
        public function onEnd(SpanData $span): void
        {
            if ($this->shutdown || count($this->queue) >= $this->maxQueueSize) {
                return;
            } $this->queue[] = $span;
        }
        public function flush(): void
        {
            if ($this->queue === [] || $this->shutdown) {
                return;
            }
            $policy = RetryBackoffPolicy::fromEnvironment();
            while ($this->queue !== []) {
                $batch = array_splice($this->queue, 0, min($this->batchSize, count($this->queue)));
                $retryIndex = 0;
                while (true) {
                    try {
                        $this->exporter->export($batch);
                        break;
                    } catch (\InvalidArgumentException) {
                        break;
                    } catch (\Throwable) {
                        if (!$policy->shouldRetry($retryIndex)) {
                            break;
                        }$sleep = $policy->delayMs($retryIndex);
                        if ($sleep > 0) {
                            \Zef\Framework\Runtime\BlockingSleeper::sleepMilliseconds($sleep);
                        }++$retryIndex;
                    }
                }
            }
        }
        public function shutdown(): void
        {
            if ($this->shutdown) {
                return;
            }
            $deadline = microtime(true) + self::envInt('ZEF_OTEL_SHUTDOWN_DRAIN_MS', 2000, 0, 60000) / 1000;
            while ($this->queue !== [] && microtime(true) < $deadline) {
                $batch = array_splice($this->queue, 0, min($this->batchSize, count($this->queue)));
                try {
                    $this->exporter->export($batch);
                } catch (\Throwable) {
                }
            }
            $this->shutdown = true;
            try {
                $this->exporter->shutdown();
            } catch (\Throwable) {
            } $this->queue = [];
        }
        public function isInMemoryExporter(): bool
        {
            return $this->exporter instanceof InMemorySpanExporter;
        }
        private static function envInt(string $name, int $default, int $min, int $max): int
        {
            $raw = getenv($name);
            if ($raw === false || trim($raw) === '') {
                return $default;
            } if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
                return $default;
            } return max($min, min($max, (int) $raw));
        }
    }
}
