<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    /**
     * Immutable retry policy for delivery attempts.
     *
     * Bounds (validated in the constructor): maxAttempts 1..64; delay values
     * 0..300_000 ms; deadlineMs >= 0 (0 = no deadline). A delay of 0 means
     * "no delay" rather than "immediate retry budget exhausted".
     */
    final readonly class RetryPolicy
    {
        public function __construct(
            public int $maxAttempts,
            public int $initialDelayMs = 0,
            public int $maxDelayMs = 30_000,
            public int $deadlineMs = 0,
            public int $maxCumulativeDelayMs = 30_000,
        ) {
            if ($maxAttempts < 1 || $maxAttempts > 64) {
                throw new \InvalidArgumentException('maxAttempts must be between 1 and 64.');
            }
            if ($initialDelayMs < 0 || $initialDelayMs > 300_000) {
                throw new \InvalidArgumentException('initialDelayMs must be between 0 and 300000.');
            }
            if ($maxDelayMs < 0 || $maxDelayMs > 300_000) {
                throw new \InvalidArgumentException('maxDelayMs must be between 0 and 300000.');
            }
            if ($deadlineMs < 0) {
                throw new \InvalidArgumentException('deadlineMs must be >= 0.');
            }
            if ($maxCumulativeDelayMs < 0 || $maxCumulativeDelayMs > 300_000) {
                throw new \InvalidArgumentException('maxCumulativeDelayMs must be between 0 and 300000.');
            }
        }

        /**
         * Exponential backoff delay for the given next attempt number (>= 2).
         *
         * delay = min(maxDelayMs, initialDelayMs * 2^(nextAttemptNumber-2)),
         * with the exponent capped at 20 to avoid overflow. Returns 0 when
         * initialDelayMs or maxDelayMs is 0.
         *
         * @throws \InvalidArgumentException when nextAttemptNumber < 2.
         */
        public function delayMs(int $nextAttemptNumber): int
        {
            if ($nextAttemptNumber < 2) {
                throw new \InvalidArgumentException('nextAttemptNumber must be >= 2.');
            }
            if ($this->initialDelayMs === 0 || $this->maxDelayMs === 0) {
                return 0;
            }
            $exponent = min(20, $nextAttemptNumber - 2);
            $multiplier = 1 << $exponent;
            return min($this->maxDelayMs, $this->initialDelayMs * $multiplier);
        }
    }
}
