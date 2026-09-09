<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    use Zef\Framework\Transport\RemoteTransportResult;

    /**
     * Immutable outcome of a delivery lifecycle.
     *
     * state is the final DeliveryState; attemptCount is the number of attempts
     * performed (0..64, 0 = never attempted). transportResult carries the last
     * transport response when one exists; retryDecision is present when the
     * delivery stopped at a retry boundary (allowed=false with the reason).
     */
    final readonly class DeliveryResult
    {
        public function __construct(
            public DeliveryState $state,
            public int $attemptCount,
            public ?RemoteTransportResult $transportResult = null,
            public ?RetryDecision $retryDecision = null,
        ) {
            if ($attemptCount < 0 || $attemptCount > 64) {
                throw new \InvalidArgumentException('attemptCount must be between 0 and 64.');
            }
        }
    }
}
