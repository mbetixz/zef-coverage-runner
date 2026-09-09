<?php

declare(strict_types=1);

namespace Zef\Framework\Cache {
    interface CacheStoreInterface
    {
        public function get(string $key): ?CacheItem;
        public function set(string $key, CacheItem $item): void;
        public function delete(string $key): void;
        public function has(string $key): bool;
        public function clear(): void;
    }
}
