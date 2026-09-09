<?php

declare(strict_types=1);

namespace Zef\Framework\Job {
    interface JobIdempotencyStoreInterface
    {
        public function remember(string $key, callable $producer, int $ttlSeconds = 3600): mixed;
    }
}
