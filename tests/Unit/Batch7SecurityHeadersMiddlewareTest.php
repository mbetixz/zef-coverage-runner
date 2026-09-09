<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;
use Zef\Middleware\SecurityHeadersMiddleware;

/**
 * Coverage for SecurityHeadersMiddleware: immutable default headers,
 * optional CSP / HSTS / Permissions-Policy toggles and scheme gating.
 * Deterministic: fixed policy arrays and fixed request inputs only.
 */
final class Batch7SecurityHeadersMiddlewareTest extends TestCase
{
    private function httpsRequest(): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getScheme')->willReturn('https');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        return $request;
    }

    private function httpRequest(): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getScheme')->willReturn('http');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        return $request;
    }

    private function handler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200, [], 'ok'));
        return $handler;
    }

    public function testDefaultHeadersAlwaysApplied(): void
    {
        $response = (new SecurityHeadersMiddleware())->process($this->httpsRequest(), $this->handler());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('none', $response->getHeaderLine('X-Permitted-Cross-Domain-Policies'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Opener-Policy'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
    }

    public function testPermissionsPolicyDefaultOnAndToggleable(): void
    {
        $default = (new SecurityHeadersMiddleware())->process($this->httpsRequest(), $this->handler());
        self::assertStringContainsString('camera=()', $default->getHeaderLine('Permissions-Policy'));

        $disabled = (new SecurityHeadersMiddleware(['permissionsPolicy' => false]))->process($this->httpsRequest(), $this->handler());
        self::assertFalse($disabled->hasHeader('Permissions-Policy'));
    }

    public function testCspDisabledByDefaultAndEnabledViaPolicy(): void
    {
        $default = (new SecurityHeadersMiddleware())->process($this->httpsRequest(), $this->handler());
        self::assertFalse($default->hasHeader('Content-Security-Policy'));

        $enabled = (new SecurityHeadersMiddleware(['csp' => true]))->process($this->httpsRequest(), $this->handler());
        self::assertSame("default-src 'self'; frame-ancestors 'none'; base-uri 'self'", $enabled->getHeaderLine('Content-Security-Policy'));

        $custom = (new SecurityHeadersMiddleware(['csp' => true, 'contentSecurityPolicy' => "default-src 'none'"]))->process($this->httpsRequest(), $this->handler());
        self::assertSame("default-src 'none'", $custom->getHeaderLine('Content-Security-Policy'));
    }

    public function testHstsOnlyOverHttps(): void
    {
        $policy = ['hsts' => true];
        $https = (new SecurityHeadersMiddleware($policy))->process($this->httpsRequest(), $this->handler());
        self::assertSame('max-age=31536000; includeSubDomains', $https->getHeaderLine('Strict-Transport-Security'));

        $http = (new SecurityHeadersMiddleware($policy))->process($this->httpRequest(), $this->handler());
        self::assertFalse($http->hasHeader('Strict-Transport-Security'));
    }

    public function testAllPoliciesTogetherPreserveExistingResponseHeaders(): void
    {
        $policy = ['hsts' => true, 'csp' => true, 'contentSecurityPolicy' => "default-src 'self'", 'permissionsPolicy' => true];
        $handler = $this->createMock(RequestHandlerInterface::class);
        $inner = (new Response(200, ['X-Custom' => 'kept'], 'ok'))->withHeader('Content-Type', 'application/json');
        $handler->method('handle')->willReturn($inner);

        $response = (new SecurityHeadersMiddleware($policy))->process($this->httpsRequest(), $handler);
        self::assertSame('kept', $response->getHeaderLine('X-Custom'));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testEmptyPolicyArrayMatchesDefaults(): void
    {
        $response = (new SecurityHeadersMiddleware([]))->process($this->httpRequest(), $this->handler());
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
    }
}
