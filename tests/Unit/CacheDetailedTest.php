<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\CacheClockInterface;
use Zef\Framework\Cache\CacheItem;
use Zef\Framework\Cache\DefaultCacheKeyNormalizer;
use Zef\Framework\Cache\InMemoryCache;
use Zef\Framework\Cache\InMemoryCacheStore;
use Zef\Framework\Cache\SystemCacheClock;

final class CacheDetailedClock implements CacheClockInterface
{
    public function __construct(private int $now) {}

    #[\Override]
    public function nowUnixNano(): int
    {
        return $this->now;
    }

    public function advanceNano(int $nano): void
    {
        $this->now += $nano;
    }
}

/**
 * Detailed coverage for the Cache foundation family:
 * InMemoryCacheStore (FIFO eviction, expiry, capacity), CacheItem,
 * DefaultCacheKeyNormalizer, InMemoryCache, SystemCacheClock and the
 * CacheClockInterface contract.
 */
final class CacheDetailedTest extends TestCase
{
    public function testStoreExpiryRemovesEntryOnRead(): void
    {
        $clock = new CacheDetailedClock(1_000);
        $store = new InMemoryCacheStore(maxEntries: 10, clock: $clock);
        $store->set('key-a', new CacheItem('v', expiresAtUnixNano: 500));
        self::assertNull($store->get('key-a'), 'expired item must read as missing');
        self::assertFalse($store->has('key-a'));
        self::assertSame(0, $store->size());
    }

    public function testStoreRejectsInvalidCapacity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InMemoryCacheStore(maxEntries: 0);
    }

    public function testStoreEvictsOldestWhenFull(): void
    {
        $store = new InMemoryCacheStore(maxEntries: 2);
        $store->set('first', new CacheItem('1'));
        $store->set('second', new CacheItem('2'));
        // Inserting a new key when full evicts the oldest entry (FIFO).
        $store->set('third', new CacheItem('3'));
        self::assertSame(2, $store->size());
        self::assertNull($store->get('first'), 'FIFO eviction must drop the oldest');
        self::assertSame('2', $store->get('second')?->value);
        self::assertSame('3', $store->get('third')?->value);
    }

    public function testStoreOverwriteExistingKeyDoesNotEvict(): void
    {
        $store = new InMemoryCacheStore(maxEntries: 2);
        $store->set('a', new CacheItem('1'));
        $store->set('b', new CacheItem('2'));
        $store->set('a', new CacheItem('updated'));
        self::assertSame(2, $store->size(), 'overwrite must not grow the store');
        self::assertSame('updated', $store->get('a')?->value);
        self::assertSame('2', $store->get('b')?->value);
    }

    public function testCacheGetReturnsDefaultOnExpiredTtl(): void
    {
        $clock = new CacheDetailedClock(1_000);
        $cache = new InMemoryCache(new InMemoryCacheStore(clock: $clock), clock: $clock);
        $cache->set('short', 'payload', ttlSeconds: 1);
        $clock->advanceNano(1_000_000_000);
        self::assertSame('fallback', $cache->get('short', 'fallback'));
    }

    public function testCacheSetRejectsZeroAndNegativeTtl(): void
    {
        $cache = new InMemoryCache(new InMemoryCacheStore());
        foreach ([0, -1, -100] as $bad) {
            try {
                $cache->set('k', 'v', $bad);
                self::fail("ttl {$bad} must throw");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCacheDeleteAndClearRoundTrip(): void
    {
        $cache = new InMemoryCache(new InMemoryCacheStore());
        $cache->set('x', 1);
        $cache->set('y', 2);
        $cache->delete('x');
        self::assertFalse($cache->has('x'));
        self::assertTrue($cache->has('y'));
        $cache->clear();
        self::assertFalse($cache->has('y'));
    }

    public function testNormalizerTrimsAndValidates(): void
    {
        $n = new DefaultCacheKeyNormalizer();
        self::assertSame('abc', $n->normalize('  abc  '));
        self::assertSame('a:b/c.d_e-f', $n->normalize('a:b/c.d_e-f'));
        foreach (['', ' ', 'has space', 'bad\nkey', str_repeat('x', 251), 'emoji😀'] as $bad) {
            try {
                $n->normalize($bad);
                self::fail("key '{$bad}' must throw");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCacheItemExpiryDefaultsToNow(): void
    {
        $item = new CacheItem('x', expiresAtUnixNano: 1);
        // hrtime(true) is far beyond 1ns on any real system.
        self::assertTrue($item->isExpired());
        $never = new CacheItem('x');
        self::assertFalse($never->isExpired());
    }

    public function testSystemClockReturnsPositiveValue(): void
    {
        self::assertGreaterThan(0, (new SystemCacheClock())->nowUnixNano());
    }

    public function testCachePersistsScalarAndArrayValues(): void
    {
        $cache = new InMemoryCache(new InMemoryCacheStore());
        $cache->set('int', 42);
        $cache->set('null', null);
        $cache->set('arr', ['a' => 1, 'b' => [true]]);
        self::assertSame(42, $cache->get('int'));
        self::assertNull($cache->get('null', 'default-null'), 'null value must be distinguishable via default only when absent');
        self::assertSame(['a' => 1, 'b' => [true]], $cache->get('arr'));
        self::assertSame('missing-default', $cache->get('absent', 'missing-default'));
    }
}
