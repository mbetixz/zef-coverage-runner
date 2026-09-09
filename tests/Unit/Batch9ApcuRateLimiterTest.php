<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Security\ApcuRateLimiter;
use Zef\Framework\Security\RateLimitDecision;

/**
 * Batch 9 coverage: ApcuRateLimiter. The APCu extension is NOT installed in
 * this environment, so behavioural tests are skipped here (guard below). The
 * constructor validation branches run unconditionally and are asserted now;
 * the full fixed-window semantics are exercised on any runner where APCu is
 * present (apc.enable_cli=1). Deterministic by construction: no clock inputs
 * other than time() used internally by the limiter itself.
 */
final class Batch9ApcuRateLimiterTest extends TestCase
{
    public function testConstructorRejectsZeroMaxKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxKeys must be >= 1');
        new ApcuRateLimiter(0);
    }

    public function testConstructorRequiresApcuExtension(): void
    {
        if (function_exists('apcu_fetch')) {
            $this->markTestSkipped('apcu present; constructor accepts.');
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APCu extension is required');
        new ApcuRateLimiter(10);
    }

    public function testCheckRejectsEmptyKey(): void
    {
        if (!function_exists('apcu_fetch')) {
            $this->markTestSkipped('APCu extension not available; constructor refuses.');
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rate limit key must not be empty');
        (new ApcuRateLimiter())->check('', 10, 60);
    }

    public function testCheckRejectsInvalidLimitOrWindow(): void
    {
        if (!function_exists('apcu_fetch')) {
            $this->markTestSkipped('APCu extension not available; constructor refuses.');
        }
        try {
            (new ApcuRateLimiter())->check('k', 0, 60);
            self::fail('limit 0 must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('limit and windowSeconds must be >= 1', $e->getMessage());
        }
        try {
            (new ApcuRateLimiter())->check('k', 10, 0);
            self::fail('window 0 must throw');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testWindowAdmissionWithinLimit(): void
    {
        if (!function_exists('apcu_fetch') || !function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available.');
        }
        if (!apcu_enabled()) {
            $this->markTestSkipped('APCu not enabled for CLI.');
        }
        $limiter = new ApcuRateLimiter(1000);
        $decision = $limiter->check('windowed-key-' . hash('sha256', __METHOD__), 3, 60);
        self::assertInstanceOf(RateLimitDecision::class, $decision);
        self::assertTrue($decision->allowed);
        self::assertSame(3, $decision->limit);
        self::assertSame(2, $decision->remaining);
    }

    public function testWindowDenialAfterLimitExceeded(): void
    {
        if (!function_exists('apcu_fetch') || !function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available.');
        }
        if (!apcu_enabled()) {
            $this->markTestSkipped('APCu not enabled for CLI.');
        }
        $key = 'denied-key-' . hash('sha256', __METHOD__);
        $limiter = new ApcuRateLimiter(1000);
        $first = $limiter->check($key, 2, 60);
        $second = $limiter->check($key, 2, 60);
        $third = $limiter->check($key, 2, 60);
        self::assertTrue($first->allowed);
        self::assertTrue($second->allowed);
        self::assertFalse($third->allowed);
        self::assertSame(0, $third->remaining);
        self::assertGreaterThanOrEqual(1, $third->retryAfter);
    }
}
