<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    /**
     * Immutable outcome of a retry evaluation.
     *
     * allowed=false with a specific reason is a terminal decision for this
     * attempt; allowed=true means SAFE_TO_RETRY and carries the backoff delay
     * to wait before nextAttemptNumber. nextAttemptNumber is always >= 1;
     * delayMs >= 0 (0 = retry immediately).
     */
    final readonly class RetryDecision
    {
        public function __construct(
            public bool $allowed,
            public RetryDecisionReason $reason,
            public int $nextAttemptNumber,
            public int $delayMs,
        ) {
            if ($nextAttemptNumber < 1 || $delayMs < 0) {
                throw new \InvalidArgumentException('Retry decision values are invalid.');
            }
        }
    }
}
