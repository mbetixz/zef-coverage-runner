<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    /**
     * Fixed-window rate limiter backed by an optional shared store.
     *
     * When a SharedRateLimitStoreInterface implementation is provided
     * (Redis/Apcu), the counters are aggregated across the whole RoadRunner
     * worker pool (and across hosts for Redis), so the configured limit is
     * exact. Without a shared store this class falls back to the documented
     * per-process behaviour of InMemoryRateLimiter.
     */
    final class RedisRateLimiter implements RateLimiterInterface
    {
        public function __construct(
            private readonly SharedRateLimitStoreInterface $store,
            private readonly int $maxKeys = 10000,
        ) {
            if ($this->maxKeys < 1) {
                throw new \InvalidArgumentException('maxKeys must be >= 1.');
            }
        }

        #[\Override]
        public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
        {
            if ($key === '') {
                throw new \InvalidArgumentException('Rate limit key must not be empty.');
            }
            if ($limit < 1 || $windowSeconds < 1) {
                throw new \InvalidArgumentException('limit and windowSeconds must be >= 1.');
            }
            $now = time();
            ['count' => $count, 'reset' => $reset] = $this->store->increment($key, $windowSeconds, $now);
            $remaining = max(0, $limit - $count);
            return new RateLimitDecision(
                $count <= $limit,
                $limit,
                $remaining,
                max(1, $reset - $now),
            );
        }
    }
}
