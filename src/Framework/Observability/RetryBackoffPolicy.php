<?php

declare(strict_types=1);

namespace Zef\Framework\Observability;

/** Bounded retry policy for asynchronous telemetry export. */
final readonly class RetryBackoffPolicy
{
    public function __construct(
        public int $maxRetries,
        public int $initialDelayMs,
        public int $maxDelayMs,
    ) {
        if ($maxRetries < 0 || $initialDelayMs < 0 || $maxDelayMs < $initialDelayMs) {
            throw new \InvalidArgumentException('Invalid telemetry retry policy.');
        }
    }

    public static function fromEnvironment(): self
    {
        $initial = self::envInt('ZEF_OTEL_RETRY_DELAY_MS', 100, 0, 10000);
        return new self(
            self::envInt('ZEF_OTEL_RETRY_ATTEMPTS', 2, 0, 10),
            $initial,
            max($initial, self::envInt('ZEF_OTEL_RETRY_DELAY_CAP_MS', 1000, 0, 60000)),
        );
    }

    public function shouldRetry(int $retryIndex): bool
    {
        if ($retryIndex < 0) {
            throw new \InvalidArgumentException('Retry index must be non-negative.');
        }
        return $retryIndex < $this->maxRetries;
    }

    public function delayMs(int $retryIndex): int
    {
        if ($retryIndex < 0) {
            throw new \InvalidArgumentException('Retry index must be non-negative.');
        }
        return min($this->maxDelayMs, $this->initialDelayMs * (2 ** $retryIndex));
    }

    private static function envInt(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '' || filter_var($raw, FILTER_VALIDATE_INT) === false) {
            return $default;
        }
        return max($min, min($max, (int) $raw));
    }
}
