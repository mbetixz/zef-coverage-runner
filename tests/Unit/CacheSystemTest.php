<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\CacheClockInterface;
use Zef\Framework\Cache\DefaultCacheKeyNormalizer;
use Zef\Framework\Cache\InMemoryCache;
use Zef\Framework\Cache\InMemoryCacheStore;
use Zef\Framework\Cache\CacheItem;

final class TestCacheClock implements CacheClockInterface
{
    public function __construct(private int $nowUnixNano) {}
    #[\Override]
    public function nowUnixNano(): int { return $this->nowUnixNano; }
    public function advanceSeconds(int $seconds): void { $this->nowUnixNano += $seconds * 1_000_000_000; }
}

final class CacheSystemTest extends TestCase
{
    public function testLegacyBehavior(): void
    {
        $clock = new TestCacheClock(1_000_000_000);
        $store = new InMemoryCacheStore(2, $clock);
        $cache = new InMemoryCache($store, new DefaultCacheKeyNormalizer(), $clock);
        $assert = static function (bool $cond, string $msg): void { if (!$cond) throw new RuntimeException($msg); };

        $cache->set('user:1', ['name' => 'alpha']);
        $assert($cache->has('user:1') && is_array($cache->get('user:1')) && $cache->get('user:1')['name'] === 'alpha', 'basic cache failed');

        $cache->set('ttl:test', 'value', 2);
        $clock->advanceSeconds(1);
        $assert($cache->get('ttl:test') === 'value', 'ttl premature expiry failed');
        $clock->advanceSeconds(1);
        $assert($cache->get('ttl:test', 'fallback') === 'fallback' && !$cache->has('ttl:test'), 'ttl expiry failed');

        $cache->set('a:key', 'A');
        $cache->set('b:key', 'B');
        $cache->set('c:key', 'C');
        $assert($cache->get('a:key', null) === null && $cache->get('b:key') === 'B' && $cache->get('c:key') === 'C', 'bounded eviction failed');

        $cache->delete('b:key');
        $assert(!$cache->has('b:key'), 'delete failed');
        $cache->clear();
        $assert(!$cache->has('c:key') && $store->size() === 0, 'clear failed');

        foreach (['', 'bad key', str_repeat('x', 251), "bad\nkey"] as $invalid) {
            $rejected = false;
            try { $cache->get($invalid); } catch (InvalidArgumentException) { $rejected = true; }
            $assert($rejected, 'invalid key accepted');
        }
        $zeroRejected = false;
        try { $cache->set('ttl:zero', 'x', 0); } catch (InvalidArgumentException) { $zeroRejected = true; }
        $assert($zeroRejected, 'zero ttl accepted');

        $item = new CacheItem('x', 5);
        $assert($item->isExpired(5) && !$item->isExpired(4), 'item expiry contract failed');
        $this->addToAssertionCount(1);
    }
}
