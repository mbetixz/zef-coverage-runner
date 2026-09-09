<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    /**
     * Immutable record of one delivery attempt.
     *
     * attemptNumber is 1-based (1..64). attemptId is a caller-unique id for
     * this single attempt (1-128 bytes), distinct from the operation id.
     */
    final readonly class DeliveryAttempt
    {
        public function __construct(
            public DeliveryOperation $operation,
            public int $attemptNumber,
            public string $attemptId,
        ) {
            if ($attemptNumber < 1 || $attemptNumber > 64) {
                throw new \InvalidArgumentException('attemptNumber must be between 1 and 64.');
            }
            if ($attemptId === '' || strlen($attemptId) > 128) {
                throw new \InvalidArgumentException('attemptId must be 1-128 bytes.');
            }
        }
    }
}
