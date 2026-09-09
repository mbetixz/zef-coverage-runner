<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Middleware\TimingMiddleware;

/**
 * Coverage for SecurityRuntimeMiddleware (request-id normalization,
 * rate limiting, origin policy, CSRF safe/unsafe paths, cookie issuance,
 * header emission) and TimingMiddleware.
 * Deterministic: fixed policies, injected limiter, fixed header inputs.
 */
final class Batch7SecurityRuntimeMiddlewareTest extends TestCase
{
    /** @param array<string,string> $headers */
    private function request(string $method, array $headers = [], string $scheme = 'https'): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getScheme')->willReturn($scheme);
        $uri->method('getPath')->willReturn('/x');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => $headers[$name] ?? '',
        );
        $request->method('getAttribute')->willReturn(null);
        $request->method('withAttribute')->willReturnSelf();
        return $request;
    }

    private function handler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200, [], 'ok'));
        return $handler;
    }

    public function testRateLimitExceededReturns429(): void
    {
        $policy = new SecurityPolicy(
            rateLimitEnabled: true,
            rateLimitMaxRequests: 1,
            rateLimitWindowSeconds: 60,
        );
        // Fixed clock: both requests land in the same window second, so the
        // emitted Retry-After is deterministic ('60') instead of racing the
        // real second boundary ('60' vs '59') when time() advances mid-test.
        $currentTime = 1_700_000_000;
        $limiter = new InMemoryRateLimiter(now: static function () use (&$currentTime): int { return $currentTime; });
        $middleware = new SecurityRuntimeMiddleware($policy, $limiter);
        self::assertSame(200, $middleware->process($this->request('GET', ['X-Request-ID' => 'r-1']), $this->handler())->getStatusCode());
        $response = $middleware->process($this->request('GET', ['X-Request-ID' => 'r-2']), $this->handler());
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
        self::assertSame('1', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('60', $response->getHeaderLine('Retry-After'));
    }

    public function testRateLimitRetryAfterAtSecondBoundaryIsExact(): void
    {
        $policy = new SecurityPolicy(
            rateLimitEnabled: true,
            rateLimitMaxRequests: 1,
            rateLimitWindowSeconds: 60,
        );
        // Mutable fake clock reproducing the original race: request 1 lands at
        // second :59, request 2 at :60 of the same fixed window. The window
        // reset is pinned at :119, so exactly 59 full seconds remain — the
        // header must be the exact '59' (deterministic), never a flaky
        // '60'/'59' flip depending on which side of the second boundary the
        // real clock happened to be on.
        $currentTime = 1_700_000_059;
        $limiter = new InMemoryRateLimiter(now: static function () use (&$currentTime): int { return $currentTime; });
        $middleware = new SecurityRuntimeMiddleware($policy, $limiter);
        self::assertSame(200, $middleware->process($this->request('GET', ['X-Request-ID' => 'r-1']), $this->handler())->getStatusCode());

        $currentTime = 1_700_000_060;
        $response = $middleware->process($this->request('GET', ['X-Request-ID' => 'r-2']), $this->handler());
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('59', $response->getHeaderLine('Retry-After'));

        // Fixed-window reset boundary: at :119 the window expires and the key
        // is admitted again with a fresh window.
        $currentTime = 1_700_000_119;
        self::assertSame(200, $middleware->process($this->request('GET', ['X-Request-ID' => 'r-3']), $this->handler())->getStatusCode());
    }

    public function testRateLimitHeadersAttachedOnAllowedRequest(): void
    {
        $policy = new SecurityPolicy(
            rateLimitEnabled: true,
            rateLimitMaxRequests: 100,
            rateLimitWindowSeconds: 60,
        );
        $limiter = new InMemoryRateLimiter();
        $response = (new SecurityRuntimeMiddleware($policy, $limiter))
            ->process($this->request('GET', ['X-Request-ID' => 'r-1']), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('99', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function testInvalidRequestIdIsReplaced(): void
    {
        $policy = new SecurityPolicy();
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter());
        $response = $middleware->process($this->request('GET', ['X-Request-ID' => 'bad id']), $this->handler());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $response->getHeaderLine('X-Request-ID'));
    }

    public function testOriginDeniedReturns403(): void
    {
        $policy = new SecurityPolicy(
            allowedOrigins: ['https://app.example'],
            originEnabled: true,
        );
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter());
        $response = $middleware->process(
            $this->request('GET', ['X-Request-ID' => 'r-1', 'Origin' => 'https://evil.example']),
            $this->handler(),
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Origin denied', (string) $response->getBody());
    }

    public function testOriginAllowedPasses(): void
    {
        $policy = new SecurityPolicy(
            csrfEnabled: false,
            allowedOrigins: ['https://app.example'],
            originEnabled: true,
        );
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter());
        $response = $middleware->process(
            $this->request('GET', ['Origin' => 'https://app.example']),
            $this->handler(),
        );
        self::assertSame(200, $response->getStatusCode());
    }

    public function testCsrfSafeMethodIssuesCookieWhenMissing(): void
    {
        $policy = new SecurityPolicy(
            csrfEnabled: true,
            csrfSecret: str_repeat('s', 32),
            csrfHttpOnlyCookie: true,
        );
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter());
        $response = $middleware->process($this->request('GET', ['X-Request-ID' => 'r-1']), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('ZEF-XSRF-TOKEN=', $cookie);
        self::assertStringContainsString('Path=/', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);
        self::assertStringContainsString('Secure', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
    }

    public function testCsrfSafeMethodWithExistingCookieDoesNotReissue(): void
    {
        $policy = new SecurityPolicy(csrfEnabled: false);
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter());
        $response = $middleware->process($this->request('GET', []), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        // CSRF disabled: no cookie header expected.
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testCsrfUnsafeMethodRejectsMissingTokenPair(): void
    {
        $policy = new SecurityPolicy(
            csrfEnabled: true,
            csrfSecret: str_repeat('s', 32),
        );
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter());
        $response = $middleware->process($this->request('POST', ['X-Request-ID' => 'r-1']), $this->handler());
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('CSRF validation failed', (string) $response->getBody());
    }

    public function testCsrfUnsafeMethodAcceptsValidTokenPair(): void
    {
        $policy = new SecurityPolicy(
            csrfEnabled: true,
            csrfSecret: str_repeat('s', 32),
            csrfSecureCookie: false,
            csrfHttpOnlyCookie: false,
        );
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter());
        // Obtain a token from the manager via a GET pass (cookie issuance path).
        $getResponse = $middleware->process($this->request('GET', ['X-Request-ID' => 'r-1']), $this->handler());
        $cookie = $getResponse->getHeaderLine('Set-Cookie');
        self::assertNotSame('', $cookie);
        preg_match('/ZEF-XSRF-TOKEN=([^;]+)/', $cookie, $matches);
        $token = $matches[1] ?? '';
        self::assertNotSame('', $token);

        $response = $middleware->process(
            $this->request('POST', [
                'X-Request-ID' => 'r-2',
                'Cookie' => 'ZEF-XSRF-TOKEN=' . $token,
                'X-CSRF-Token' => $token,
            ]),
            $this->handler(),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'), 'no new cookie on unsafe valid request');
    }

    public function testCsrfUnsafeMethodRejectsMismatchedTokens(): void
    {
        $policy = new SecurityPolicy(
            csrfEnabled: true,
            csrfSecret: str_repeat('s', 32),
        );
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter());
        $getResponse = $middleware->process($this->request('GET', ['X-Request-ID' => 'r-1']), $this->handler());
        preg_match('/ZEF-XSRF-TOKEN=([^;]+)/', $getResponse->getHeaderLine('Set-Cookie'), $matches);
        $token = $matches[1] ?? '';
        $response = $middleware->process(
            $this->request('POST', [
                'Cookie' => 'ZEF-XSRF-TOKEN=' . $token,
                'X-CSRF-Token' => 'forged-token',
            ]),
            $this->handler(),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testCsrfDisabledSkipsChecksAndHeaders(): void
    {
        $policy = new SecurityPolicy(csrfEnabled: false, csrfSecret: '');
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter());
        $response = $middleware->process($this->request('POST', ['X-Request-ID' => 'r-1']), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testTrustedProxiesFromRequestAttributeOverride(): void
    {
        $policy = new SecurityPolicy();
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter(), ['10.0.0.1']);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getScheme')->willReturn('http');
        $uri->method('getPath')->willReturn('/x');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturnMap([
            ['X-Request-ID', 'r-attr'],
            ['X-Forwarded-For', '203.0.113.5'],
            ['Origin', ''],
            ['Cookie', ''],
        ]);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '10.0.0.9']);
        $request->method('getAttribute')->willReturnCallback(
            static fn (string $name, mixed $default = null): mixed => match ($name) {
                '__zef_trusted_proxies' => ['10.0.0.0/24'],
                default => $default,
            },
        );
        $request->method('withAttribute')->willReturnSelf();
        $response = $middleware->process($request, $this->handler());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRateLimiterFailureReturns503(): void
    {
        $policy = new SecurityPolicy(rateLimitEnabled: true);
        $limiter = $this->createMock(Zef\Framework\Security\RateLimiterInterface::class);
        $limiter->method('check')->willThrowException(new RuntimeException('redis down'));
        $middleware = new SecurityRuntimeMiddleware($policy, $limiter);
        $response = $middleware->process($this->request('GET', ['X-Request-ID' => 'r-1']), $this->handler());
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
    }

    public function testTrustedProxyAttributeEnablesForwardedFor(): void
    {
        $policy = new SecurityPolicy(csrfEnabled: false);
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter(), ['10.0.0.1']);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getPath')->willReturn('/x');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturnMap([
            ['X-Request-ID', 'r-attr'],
            ['X-Forwarded-For', '203.0.113.5'],
            ['Origin', ''],
        ]);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '10.0.0.9']);
        $request->method('getAttribute')->willReturn(['10.0.0.0/24']);
        $seen = null;
        $request->method('withAttribute')->willReturnCallback(
            function (string $name, mixed $value) use (&$seen) {
                $seen = $value instanceof Zef\Framework\Security\SecurityContext ? $value : null;
                return $this->request('GET', ['X-Request-ID' => 'r-attr']);
            },
        );
        $middleware->process($request, $this->handler());
        self::assertInstanceOf(Zef\Framework\Security\SecurityContext::class, $seen);
        self::assertSame('203.0.113.5', $seen->clientIp);
    }

    // --- TimingMiddleware -----------------------------------------------------

    public function testTimingMiddlewareAddsHeader(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200, [], 'ok'));
        $request = $this->createMock(ServerRequestInterface::class);
        $response = (new TimingMiddleware())->process($request, $handler);
        self::assertMatchesRegularExpression('/^\d+(\.\d+)?ms$/', $response->getHeaderLine('X-Response-Time'));
    }
}
