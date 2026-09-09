<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;
use Zef\Middleware\CorsMiddleware;

/**
 * Coverage for CorsMiddleware: allowlist normalization, allow-all mode,
 * preflight handling, deny-default, Vary normalization and origin parsing.
 * Deterministic: no wall-clock, random or locale dependence.
 */
final class Batch7CorsMiddlewareTest extends TestCase
{
    /** @param array<string,string> $headers */
    private function request(string $method, array $headers = []): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => $headers[$name] ?? '',
        );
        return $request;
    }

    private function handler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        return $handler;
    }

    public function testDenyDefaultPassesThroughWithoutHeaders(): void
    {
        $middleware = new CorsMiddleware();
        $request = $this->request('GET', ['Origin' => 'https://evil.example']);
        $inner = new Response(200, ['Content-Type' => 'text/plain'], 'ok');
        $response = $middleware->process($request, $this->handler($inner));
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testAllowedOriginGetsHeadersOnActualRequest(): void
    {
        $middleware = new CorsMiddleware(['https://app.example']);
        $request = $this->request('POST', ['Origin' => 'https://app.example']);
        $inner = new Response(200, [], 'ok');
        $response = $middleware->process($request, $this->handler($inner));
        self::assertSame('https://app.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST, PUT, PATCH, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
    }

    public function testNormalizedOriginMatchCaseAndDefaultPort(): void
    {
        // Constructor normalizes 'https://APP.example:443' -> 'https://app.example'.
        $middleware = new CorsMiddleware(['https://APP.example:443']);
        $request = $this->request('GET', ['Origin' => 'https://app.example']);
        $response = $middleware->process($request, $this->handler(new Response(200, [], 'ok')));
        self::assertSame('https://app.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testNonDefaultPortIsPreserved(): void
    {
        $middleware = new CorsMiddleware(['http://localhost:8080']);
        $request = $this->request('GET', ['Origin' => 'http://localhost:8080']);
        $response = $middleware->process($request, $this->handler(new Response(200, [], 'ok')));
        self::assertSame('http://localhost:8080', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testDisallowedOriginGetsNoCorsHeadersOnSimpleRequest(): void
    {
        $middleware = new CorsMiddleware(['https://app.example']);
        $request = $this->request('GET', ['Origin' => 'https://evil.example']);
        $inner = new Response(200, [], 'ok');
        $response = $middleware->process($request, $this->handler($inner));
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testDisallowedOriginPreflightIsRejected(): void
    {
        $middleware = new CorsMiddleware(['https://app.example']);
        $request = $this->request('OPTIONS', ['Origin' => 'https://evil.example']);
        $response = $middleware->process($request, $this->handler(new Response(200, [], 'ok')));
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testAllowedPreflightReturns204WithHeaders(): void
    {
        $middleware = new CorsMiddleware(['https://app.example'], allowHeaders: 'Content-Type');
        $request = $this->request('OPTIONS', ['Origin' => 'https://app.example']);
        $response = $middleware->process($request, $this->handler(new Response(200, [], 'ok')));
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://app.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Content-Type', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testAllowAllWildcardMode(): void
    {
        $middleware = new CorsMiddleware('*');
        $request = $this->request('GET', ['Origin' => 'https://anything.example']);
        $response = $middleware->process($request, $this->handler(new Response(200, [], 'ok')));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testNoOriginNonBrowserClientPassesThrough(): void
    {
        $middleware = new CorsMiddleware(['https://app.example']);
        $request = $this->request('GET');
        $inner = new Response(200, [], 'ok');
        $response = $middleware->process($request, $this->handler($inner));
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testEmptyAndInvalidAllowlistEntriesAreIgnored(): void
    {
        // Empty/whitespace entries are skipped by the constructor; the runtime
        // also tolerates non-string entries, so feed them through a union.
        /** @var list<string> $allowlist */
        $allowlist = ['', '   ', 'https://valid.example'];
        $middleware = new CorsMiddleware($allowlist);
        $request = $this->request('GET', ['Origin' => 'https://valid.example']);
        $response = $middleware->process($request, $this->handler(new Response(200, [], 'ok')));
        self::assertSame('https://valid.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
        // Constructor with null (deny default) and with only blank entries.
        $deny = new CorsMiddleware(null);
        $r2 = $deny->process($this->request('GET', ['Origin' => 'https://valid.example']), $this->handler(new Response(200, [], 'ok')));
        self::assertFalse($r2->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testVaryHeaderIsDeduplicatedAndAppendsOrigin(): void
    {
        $middleware = new CorsMiddleware(['https://app.example']);
        $inner = new Response(200, ['Vary' => ['Accept-Encoding', 'origin', 'Accept-Encoding']], 'ok');
        $response = $middleware->process(
            $this->request('GET', ['Origin' => 'https://app.example']),
            $this->handler($inner),
        );
        self::assertSame('Accept-Encoding, Origin', $response->getHeaderLine('Vary'));
    }

    public function testResponseWithExistingCorsHeaderVaryStillNormalized(): void
    {
        $middleware = new CorsMiddleware(['https://app.example']);
        $inner = (new Response(200, [], 'ok'))
            ->withHeader('Access-Control-Allow-Origin', 'https://old.example')
            ->withHeader('Vary', 'Origin');
        $response = $middleware->process(
            $this->request('GET', ['Origin' => 'https://app.example']),
            $this->handler($inner),
        );
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
        self::assertSame('https://app.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testStringConstructorIsSingleEntryAllowlist(): void
    {
        $middleware = new CorsMiddleware('https://legacy.example');
        $request = $this->request('GET', ['Origin' => 'https://legacy.example']);
        $response = $middleware->process($request, $this->handler(new Response(200, [], 'ok')));
        self::assertSame('https://legacy.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testMalformedRequestOriginNeverMatches(): void
    {
        $middleware = new CorsMiddleware(['https://app.example']);
        $request = $this->request('GET', ['Origin' => 'https://app.example/path']);
        $response = $middleware->process($request, $this->handler(new Response(200, [], 'ok')));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testNullLiteralOriginIsRejectedByAllowlist(): void
    {
        $middleware = new CorsMiddleware(['null', 'https://app.example']);
        $request = $this->request('GET', ['Origin' => 'null']);
        $response = $middleware->process($request, $this->handler(new Response(200, [], 'ok')));
        self::assertSame('null', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testLowercaseSchemeInRequestOriginMatchesNormalizedAllowlist(): void
    {
        $middleware = new CorsMiddleware(['HTTPS://APP.EXAMPLE']);
        $request = $this->request('GET', ['Origin' => 'https://app.example']);
        $response = $middleware->process($request, $this->handler(new Response(200, [], 'ok')));
        self::assertSame('https://app.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
