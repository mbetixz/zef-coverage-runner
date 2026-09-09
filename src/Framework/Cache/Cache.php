<?php

declare(strict_types=1);

namespace Zef\Framework\Cache {
    // Compatibility aggregate retained for explicit legacy requires.
    // Public declarations moved to per-type files (D2 structural decomposition):
    // interface CacheInterface, interface CacheStoreInterface,
    // interface CacheSerializerInterface, interface CacheKeyNormalizerInterface,
    // interface CacheClockInterface, final class InMemoryCache,
    // final class InMemoryCacheStore, class CacheItem, class SystemCacheClock,
    // class DefaultCacheKeyNormalizer. Per-type files keep the foundation free
    // of PHP object injection surfaces, network primitives, and external
    // infrastructure coupling.
}

namespace {
    require_once __DIR__ . '/CacheItem.php';
    require_once __DIR__ . '/CacheInterface.php';
    require_once __DIR__ . '/CacheStoreInterface.php';
    require_once __DIR__ . '/CacheSerializerInterface.php';
    require_once __DIR__ . '/CacheKeyNormalizerInterface.php';
    require_once __DIR__ . '/CacheClockInterface.php';
    require_once __DIR__ . '/SystemCacheClock.php';
    require_once __DIR__ . '/DefaultCacheKeyNormalizer.php';
    require_once __DIR__ . '/InMemoryCacheStore.php';
    require_once __DIR__ . '/InMemoryCache.php';
}
