<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    /**
     * Per-process in-memory fixed-window rate limiter (zero-dependency default).
     *
     * DOCUMENTED LIMITATION (audit M-2): state lives inside ONE PHP process.
     * Under RoadRunner's worker pool every worker carries its own instance, so
     * the effective limit is multiplied by the worker count and counters are
     * lost whenever a worker is recycled. For exact, shared accounting use a
     * store shared across the pool:
     *   - RedisRateLimiter + SharedRateLimitStoreInterface (Lua-atomic, also
     *     cross-host), selected via ZEF_RATE_LIMIT_STORE=redis;
     *   - ApcuRateLimiter (per process tree, apcu), ZEF_RATE_LIMIT_STORE=apcu.
     */
    final class InMemoryRateLimiter implements RateLimiterInterface
    {
        /** @var array<string,array{count:int,reset:int}> */
        private array $buckets = [];

        /** @var \Closure():int */
        private readonly \Closure $now;

        /**
         * @param \Closure():int|null $now Optional clock source returning the
         *        current Unix timestamp in seconds. Injected by tests to make
         *        window/Retry-After arithmetic deterministic; defaults to the
         *        system clock (time()).
         */
        public function __construct(private readonly int $maxKeys = 10000, ?\Closure $now = null)
        {
            $this->now = $now ?? static fn (): int => time();
        }

        #[\Override] public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
        {
            $now = ($this->now)();
            foreach ($this->buckets as $bucketKey => $bucket) {
                if ($bucket['reset'] <= $now) {
                    unset($this->buckets[$bucketKey]);
                }
            }
            if (!isset($this->buckets[$key]) && count($this->buckets) >= $this->maxKeys) {
                throw new \RuntimeException('Rate limiter capacity exhausted.');
            }
            $bucket = $this->buckets[$key] ?? ['count' => 0, 'reset' => $now + $windowSeconds];
            if ($bucket['reset'] <= $now) {
                $bucket = ['count' => 0, 'reset' => $now + $windowSeconds];
            }
            ++$bucket['count'];
            $this->buckets[$key] = $bucket;
            $remaining = max(0, $limit - $bucket['count']);
            return new RateLimitDecision(
                $bucket['count'] <= $limit,
                $limit,
                $remaining,
                max(1, $bucket['reset'] - $now),
            );
        }
    }
}
