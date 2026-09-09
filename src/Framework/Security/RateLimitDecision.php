<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    final readonly class RateLimitDecision
    {
        public function __construct(
            public bool $allowed,
            public int $limit,
            public int $remaining,
            public int $retryAfter,
        ) {
        }
    }
}
