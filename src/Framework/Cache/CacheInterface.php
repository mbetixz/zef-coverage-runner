<?php

declare(strict_types=1);

namespace Zef\Framework\Cache {
    interface CacheInterface
    {
        public function get(string $key, mixed $default = null): mixed;
        public function set(string $key, mixed $value, ?int $ttlSeconds = null): void;
        public function delete(string $key): void;
        public function has(string $key): bool;
        public function clear(): void;
    }
}
