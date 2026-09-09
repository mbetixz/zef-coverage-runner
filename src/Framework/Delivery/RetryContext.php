<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    /**
     * Immutable snapshot of the environment in which a retry decision is made.
     *
     * monotonicNowMs comes from a monotonic clock (hrtime), not wall time, so
     * comparisons against RetryPolicy::deadlineMs are immune to wall-clock
     * jumps. cumulativeDelayMs is the sum of delays already scheduled for this
     * delivery; resourceAdmitted/securityAllowed gate retries independently of
     * the policy (defaults: true).
     */
    final readonly class RetryContext
    {
        public function __construct(
            public int $monotonicNowMs,
            public int $cumulativeDelayMs,
            public bool $resourceAdmitted = true,
            public bool $securityAllowed = true,
        ) {
            if ($monotonicNowMs < 0 || $cumulativeDelayMs < 0) {
                throw new \InvalidArgumentException('Retry context time values must be >= 0.');
            }
        }
    }
}
