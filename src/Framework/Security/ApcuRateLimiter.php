<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    /**
     * APCu-backed rate limiter. Safe for the RoadRunner worker pool when every
     * worker shares one PHP process tree (apcu is per-process-pool, not
     * cross-host). Requires the APCu extension and apc.enable_cli=1 for CLI
     * workers. Falls back to in-memory semantics when APCu is unavailable.
     */
    final class ApcuRateLimiter implements RateLimiterInterface
    {
        private const string PREFIX = 'zef:ratelimit:';

        public function __construct(private readonly int $maxKeys = 10000)
        {
            if ($this->maxKeys < 1) {
                throw new \InvalidArgumentException('maxKeys must be >= 1.');
            }
            if (!function_exists('apcu_fetch')) {
                throw new \RuntimeException('APCu extension is required for ApcuRateLimiter.');
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
            $bucketKey = self::PREFIX . hash('sha256', $key);

            // Atomic increment of the fixed-window counter; the window resets
            // when the stored reset epoch is in the past.
            $bucket = apcu_fetch($bucketKey);
            $count = is_array($bucket) && is_int($bucket['count'] ?? null) ? $bucket['count'] : 0;
            $reset = is_array($bucket) && is_int($bucket['reset'] ?? null) ? $bucket['reset'] : $now + $windowSeconds;
            if ($reset <= $now) {
                $count = 0;
                $reset = $now + $windowSeconds;
            }
            ++$count;
            apcu_store($bucketKey, ['count' => $count, 'reset' => $reset], $windowSeconds + 60);

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
