<?php

declare(strict_types=1);

namespace Zef\Framework\Transport {
    /**
     * Immutable per-attempt transport parameters.
     *
     * Bounds (enforced in the constructor): timeoutMs is 1..300_000; deadlineMs
     * is >= 0 (0 = no deadline). isCancelled() reflects the optional
     * cancellation token; hasExpired()/remainingMs() compare the caller's
     * monotonic clock reading (>= 0) against the deadline.
     */
    final readonly class TransportContext
    {
        /**
         * @throws \InvalidArgumentException when timeoutMs or deadlineMs
         *         violates the documented bounds.
         */
        public function __construct(
            public int $timeoutMs,
            public int $deadlineMs,
            public ?CancellationTokenInterface $cancellation = null,
        ) {
            if ($timeoutMs < 1 || $timeoutMs > 300_000) {
                throw new \InvalidArgumentException('Transport timeoutMs must be between 1 and 300000.');
            }
            if ($deadlineMs < 0) {
                throw new \InvalidArgumentException('Transport deadlineMs must be >= 0.');
            }
        }

        /** True when the optional cancellation token reports cancellation. */
        public function isCancelled(): bool
        {
            return $this->cancellation?->isCancellationRequested() ?? false;
        }

        /**
         * True when the given monotonic clock reading has reached the deadline.
         *
         * @throws \InvalidArgumentException when $monotonicNowMs is negative.
         */
        public function hasExpired(int $monotonicNowMs): bool
        {
            if ($monotonicNowMs < 0) {
                throw new \InvalidArgumentException('Monotonic time must be >= 0.');
            }
            return $monotonicNowMs >= $this->deadlineMs;
        }

        /**
         * Remaining budget in ms: min(timeoutMs, deadlineMs - now), floored at 0.
         *
         * @throws \InvalidArgumentException when $monotonicNowMs is negative.
         */
        public function remainingMs(int $monotonicNowMs): int
        {
            if ($monotonicNowMs < 0) {
                throw new \InvalidArgumentException('Monotonic time must be >= 0.');
            }
            return max(0, min($this->timeoutMs, $this->deadlineMs - $monotonicNowMs));
        }
    }
}
