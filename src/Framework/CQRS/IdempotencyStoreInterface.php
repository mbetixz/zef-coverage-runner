<?php

declare(strict_types=1);

namespace Zef\Framework\CQRS;

interface IdempotencyStoreInterface
{
    public function remember(string $key, callable $producer, int $ttlSeconds = 3600): mixed;
}
