<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    interface RateLimiterInterface
    {
        public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision;
    }
}
