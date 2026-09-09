<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    /**
     * Atomic fixed-window rate-limit store shared across RoadRunner worker
     * processes (and, for Redis, across hosts).
     *
     * Implementations MUST be atomic for read-modify-write on one key so that
     * the aggregate limit is exact when N workers call concurrently:
     *   - RedisRateLimiter  -> Lua script (EVAL) on a single Redis instance.
     *   - ApcuRateLimiter   -> apcu_inc()/apcu_cas(), safe across the worker
     *                          pool that shares one PHP process tree (apcu).
     * A per-process InMemoryRateLimiter remains the zero-dependency default,
     * but it is NOT shared across workers (documented limitation, M-2).
     */
    interface SharedRateLimitStoreInterface
    {
        /**
         * Atomically increments the bucket for $key and returns the post-increment
         * count together with the window reset epoch (Unix seconds).
         *
         * @return array{count:int, reset:int}
         */
        public function increment(string $key, int $windowSeconds, int $now): array;

        /**
         * Returns the current bucket state without mutating it, or null when the
         * bucket does not exist yet.
         *
         * @return array{count:int, reset:int}|null
         */
        public function peek(string $key, int $now): ?array;
    }
}
