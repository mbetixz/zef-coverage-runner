<?php

declare(strict_types=1);

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zef\Framework\Security\ApcuRateLimiter;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\RateLimitDecision;
use Zef\Framework\Security\RedisRateLimiter;
use Zef\Framework\Security\RedisSharedRateLimitStore;
use Zef\Framework\Security\SharedRateLimitStoreInterface;

/**
 * Detailed coverage for the rate-limiter family:
 * InMemoryRateLimiter, RateLimitDecision, RedisRateLimiter,
 * RedisSharedRateLimitStore (with a mocked Redis client) and the
 * SharedRateLimitStoreInterface contract.
 */
final class RateLimiterDetailedTest extends TestCase
{
    /** @return MockObject&Redis */
    private function redisMock(): MockObject&Redis
    {
        if (!class_exists('Redis')) {
            self::markTestSkipped('ext-redis not loaded in this environment');
        }
        return $this->createMock(Redis::class);
    }

    public function testInMemoryAllowsWithinLimitAndTracksRemaining(): void
    {
        $rl = new InMemoryRateLimiter();
        $d1 = $rl->check('ip:1.2.3.4', 3, 60);
        self::assertInstanceOf(RateLimitDecision::class, $d1);
        self::assertTrue($d1->allowed);
        self::assertSame(3, $d1->limit);
        self::assertSame(2, $d1->remaining);
        self::assertGreaterThanOrEqual(1, $d1->retryAfter);
        self::assertLessThanOrEqual(60, $d1->retryAfter);

        self::assertTrue($rl->check('ip:1.2.3.4', 3, 60)->allowed);
        $third = $rl->check('ip:1.2.3.4', 3, 60);
        self::assertTrue($third->allowed, 'third hit equals the limit and is still allowed');
        self::assertSame(0, $third->remaining);
        $fourth = $rl->check('ip:1.2.3.4', 3, 60);
        self::assertFalse($fourth->allowed, 'fourth hit in a 3-window must be denied');
        self::assertSame(0, $fourth->remaining);
    }

    public function testInMemoryDeniesWhenLimitExceeded(): void
    {
        $rl = new InMemoryRateLimiter();
        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($rl->check('login:user-a', 3, 60)->allowed);
        }
        $denied = $rl->check('login:user-a', 3, 60);
        self::assertFalse($denied->allowed);
        self::assertSame(0, $denied->remaining);
        self::assertSame(3, $denied->limit);
    }

    public function testInMemoryIsolatesKeys(): void
    {
        $rl = new InMemoryRateLimiter();
        $rl->check('key-a', 1, 60);
        self::assertTrue($rl->check('key-b', 1, 60)->allowed, 'keys must be isolated');
    }

    public function testInMemoryWindowResetAfterExpiry(): void
    {
        $rl = new InMemoryRateLimiter();
        self::assertTrue($rl->check('burst', 1, 1)->allowed);
        self::assertFalse($rl->check('burst', 1, 1)->allowed);
        // Simulate window passage by sleeping 1 second so the reset epoch passes.
        sleep(2);
        self::assertTrue($rl->check('burst', 1, 1)->allowed, 'bucket must reset after window');
    }

    public function testInMemoryCapacityExhaustionThrows(): void
    {
        $rl = new InMemoryRateLimiter(maxKeys: 2);
        $rl->check('k1', 5, 60);
        $rl->check('k2', 5, 60);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('capacity exhausted');
        $rl->check('k3', 5, 60);
    }

    public function testRedisRateLimiterDelegatesToStore(): void
    {
        $store = $this->createMock(SharedRateLimitStoreInterface::class);
        $store->method('increment')->willReturn(['count' => 1, 'reset' => time() + 60]);
        $rl = new RedisRateLimiter($store);
        $d = $rl->check('api-key', 5, 60);
        self::assertTrue($d->allowed);
        self::assertSame(4, $d->remaining);
        self::assertSame(5, $d->limit);
    }

    public function testRedisRateLimiterDeniesAtLimit(): void
    {
        $store = $this->createMock(SharedRateLimitStoreInterface::class);
        $store->method('increment')->willReturn(['count' => 6, 'reset' => time() + 60]);
        $rl = new RedisRateLimiter($store);
        $d = $rl->check('api-key', 5, 60);
        self::assertFalse($d->allowed);
        self::assertSame(0, $d->remaining);
    }

    public function testRedisRateLimiterRejectsEmptyKeyAndInvalidArgs(): void
    {
        $store = $this->createMock(SharedRateLimitStoreInterface::class);
        $rl = new RedisRateLimiter($store);
        try {
            $rl->check('', 5, 60);
            self::fail('empty key must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $rl->check('k', 0, 60);
            self::fail('zero limit must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $rl->check('k', 5, 0);
            self::fail('zero window must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        new RedisRateLimiter($store, maxKeys: 0);
    }

    public function testRedisStoreIncrementNewBucketSetsTtl(): void
    {
        $redis = $this->redisMock();
        $redis->method('incr')->willReturn(1);
        $redis->method('ttl')->willReturn(-1);
        $redis->expects(self::once())->method('expire')->with(self::isString(), 60);
        $store = new RedisSharedRateLimitStore($redis);
        $now = time();
        $result = $store->increment('bucket-new', 60, $now);
        self::assertSame(1, $result['count']);
        self::assertSame($now + 60, $result['reset']);
    }

    public function testRedisStoreIncrementExistingBucketDerivesResetFromTtl(): void
    {
        $redis = $this->redisMock();
        $redis->method('incr')->willReturn(3);
        $redis->method('ttl')->willReturn(30);
        $store = new RedisSharedRateLimitStore($redis);
        $now = time();
        $result = $store->increment('bucket-existing', 60, $now);
        self::assertSame(3, $result['count']);
        self::assertSame($now + 30, $result['reset']);
    }

    public function testRedisStorePeekReturnsBucketWhenPresent(): void
    {
        $redis = $this->redisMock();
        $redis->method('get')->willReturn('5');
        $redis->method('ttl')->willReturn(45);
        $store = new RedisSharedRateLimitStore($redis);
        $now = time();
        $result = $store->peek('bucket-peek', $now);
        self::assertIsArray($result);
        self::assertSame(5, $result['count']);
        self::assertSame($now + 45, $result['reset']);
    }

    public function testRedisStorePeekReturnsNullWhenMissing(): void
    {
        $redis = $this->redisMock();
        $redis->method('get')->willReturn(false);
        $store = new RedisSharedRateLimitStore($redis);
        self::assertNull($store->peek('bucket-missing', time()));
    }

    public function testRedisStorePeekReturnsNullWhenTtlMissing(): void
    {
        $redis = $this->redisMock();
        $redis->method('get')->willReturn('7');
        $redis->method('ttl')->willReturn(-1);
        $store = new RedisSharedRateLimitStore($redis);
        self::assertNull($store->peek('bucket-no-ttl', time()));
    }

    public function testRedisStoreRejectsInvalidWindow(): void
    {
        $redis = $this->redisMock();
        $store = new RedisSharedRateLimitStore($redis);
        $this->expectException(InvalidArgumentException::class);
        $store->increment('bucket', 0, time());
    }

    public function testApcuRateLimiterConstructorValidation(): void
    {
        try {
            new ApcuRateLimiter(maxKeys: 0);
            self::fail('maxKeys 0 must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        if (!function_exists('apcu_fetch')) {
            self::markTestSkipped('APCu extension not loaded');
        }
        self::assertInstanceOf(ApcuRateLimiter::class, new ApcuRateLimiter());
    }

    public function testApcuRateLimiterRejectsInvalidArgumentsWhenAvailable(): void
    {
        if (!function_exists('apcu_fetch')) {
            self::markTestSkipped('APCu extension not loaded');
        }
        $rl = new ApcuRateLimiter();
        try {
            $rl->check('', 5, 60);
            self::fail('empty key must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $rl->check('k', 0, 60);
            self::fail('zero limit must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        $rl->check('k', 5, 0);
    }

    public function testApcuRateLimiterTracksCountersWhenAvailable(): void
    {
        if (!function_exists('apcu_fetch')) {
            self::markTestSkipped('APCu extension not loaded');
        }
        $rl = new ApcuRateLimiter();
        $key = 'test-key-' . bin2hex(random_bytes(4));
        self::assertTrue($rl->check($key, 2, 60)->allowed);
        self::assertTrue($rl->check($key, 2, 60)->allowed);
        self::assertFalse($rl->check($key, 2, 60)->allowed);
        $rl = new ApcuRateLimiter();
        self::assertTrue($rl->check($key, 2, 60)->allowed, 'window not yet reset');
        self::assertFalse($rl->check($key, 2, 60)->allowed, 'counters persist in apcu');
    }

    public function testRedisStoreFailsClosedOnUnexpectedRedisReturn(): void
    {
        $redis = $this->redisMock();
        $redis->method('incr')->willReturn(false);
        $store = new RedisSharedRateLimitStore($redis);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INCR returned an unexpected value');
        $store->increment('bucket-corrupt', 60, time());
    }
}
