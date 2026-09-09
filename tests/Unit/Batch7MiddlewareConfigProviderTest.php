<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Middleware\ConfigProvider as MiddlewareConfigProvider;
use Zef\Middleware\CorsMiddleware;
use Zef\Middleware\SecurityHeadersMiddleware;

/**
 * Coverage for SecurityPolicy::fromEnvironment and the Middleware
 * ConfigProvider (env-driven CORS/rate-limit/CSRF wiring). Every test saves
 * and restores the environment in finally blocks so no state leaks between
 * tests or into other suites (determinism rule).
 */
final class Batch7MiddlewareConfigProviderTest extends TestCase
{
    /** @var list<string> */
    private array $touched = [];

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->touched as $name) {
            putenv($name);
        }
        $this->touched = [];
        parent::tearDown();
    }

    private function env(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $this->touched[] = $name;
    }

    /**
     * @return callable(mixed ...$args): object
     */
    private function factory(string $id): callable
    {
        $config = (new MiddlewareConfigProvider())->getConfig();
        $services = $config['services'] ?? [];
        if (!is_array($services) || !isset($services[$id]) || !is_array($services[$id]) || !isset($services[$id]['factory']) || !is_callable($services[$id]['factory'])) {
            self::fail("factory for '{$id}' missing");
        }
        /** @var callable(mixed ...$args): object $factory */
        $factory = $services[$id]['factory'];
        return $factory;
    }

    public function testPolicyFromEnvironmentDefaults(): void
    {
        $policy = SecurityPolicy::fromEnvironment();
        self::assertFalse($policy->rateLimitEnabled);
        self::assertSame(100, $policy->rateLimitMaxRequests);
        self::assertSame(60, $policy->rateLimitWindowSeconds);
        self::assertSame(10000, $policy->rateLimitMaxKeys);
        self::assertFalse($policy->csrfEnabled);
        self::assertFalse($policy->originEnabled);
        self::assertSame([], $policy->allowedOrigins);
        self::assertSame(32, $policy->csrfTokenBytes);
    }

    public function testPolicyFromEnvironmentFullConfig(): void
    {
        $this->env('ZEF_SECURITY_RATE_LIMIT', '1');
        $this->env('ZEF_SECURITY_RATE_LIMIT_MAX', '50');
        $this->env('ZEF_SECURITY_RATE_LIMIT_WINDOW', '30');
        $this->env('ZEF_SECURITY_RATE_LIMIT_MAX_KEYS', '500');
        $this->env('ZEF_SECURITY_CSRF', '1');
        $this->env('ZEF_SECURITY_CSRF_SECRET', str_repeat('s', 40));
        $this->env('ZEF_SECURITY_CSRF_COOKIE', 'XSRF');
        $this->env('ZEF_SECURITY_CSRF_HEADER', 'X-XSRF');
        $this->env('ZEF_SECURITY_CSRF_SECURE', '0');
        $this->env('ZEF_SECURITY_CSRF_HTTP_ONLY', '0');
        $this->env('ZEF_SECURITY_CSRF_SAMESITE', 'Lax');
        $this->env('ZEF_SECURITY_CSRF_TOKEN_BYTES', '16');
        $this->env('ZEF_SECURITY_ALLOWED_ORIGINS', ' https://a.example , https://b.example , ');
        $this->env('ZEF_SECURITY_ORIGIN_POLICY', '1');

        $policy = SecurityPolicy::fromEnvironment();
        self::assertTrue($policy->rateLimitEnabled);
        self::assertSame(50, $policy->rateLimitMaxRequests);
        self::assertSame(30, $policy->rateLimitWindowSeconds);
        self::assertSame(500, $policy->rateLimitMaxKeys);
        self::assertTrue($policy->csrfEnabled);
        self::assertSame('XSRF', $policy->csrfCookieName);
        self::assertSame('X-XSRF', $policy->csrfHeaderName);
        self::assertFalse($policy->csrfSecureCookie);
        self::assertFalse($policy->csrfHttpOnlyCookie);
        self::assertSame('Lax', $policy->csrfSameSite);
        self::assertSame(['https://a.example', 'https://b.example'], $policy->allowedOrigins);
        self::assertTrue($policy->originEnabled);
    }

    public function testPolicyExplicitOptInWithoutSecretThrows(): void
    {
        $this->env('ZEF_SECURITY_CSRF', '1');
        $this->env('ZEF_SECURITY_CSRF_SECRET', '');
        try {
            SecurityPolicy::fromEnvironment();
            self::fail('explicit CSRF opt-in without secret must throw');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testPolicyInvalidCtorValuesRejected(): void
    {
        $cases = [
            static fn () => new SecurityPolicy(csrfTokenBytes: 8),
            static fn () => new SecurityPolicy(rateLimitMaxRequests: 0),
            static fn () => new SecurityPolicy(rateLimitWindowSeconds: 0),
            static fn () => new SecurityPolicy(rateLimitMaxKeys: 0),
            static fn () => new SecurityPolicy(csrfEnabled: true, csrfSecret: 'short'),
            static fn () => new SecurityPolicy(csrfCookieName: 'bad cookie'),
            static fn () => new SecurityPolicy(csrfHeaderName: 'bad header'),
            static fn () => new SecurityPolicy(csrfSameSite: 'Bogus'),
            static fn () => new SecurityPolicy(csrfSameSite: 'None', csrfSecureCookie: false),
            static fn () => new SecurityPolicy(originEnabled: true),
        ];
        foreach ($cases as $case) {
            try {
                $case();
                self::fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPolicyOriginNormalizationAndDedupe(): void
    {
        $policy = new SecurityPolicy(
            allowedOrigins: ['https://A.example', 'https://a.example:443', 'https://b.example'],
            originEnabled: true,
        );
        self::assertSame(['https://a.example', 'https://b.example'], $policy->allowedOrigins);
    }

    public function testMiddlewareConfigProviderShape(): void
    {
        $provider = new MiddlewareConfigProvider();
        self::assertSame('middleware', $provider->getModuleName());
        $config = $provider->getConfig();
        self::assertArrayHasKey('services', $config);
        self::assertArrayHasKey('stack', $config);
        $services = $config['services'];
        self::assertIsArray($services);
        self::assertArrayHasKey('middleware.error', $services);
        self::assertArrayHasKey('middleware.timing', $services);
        self::assertArrayHasKey('middleware.cors', $services);
        self::assertArrayHasKey('middleware.security', $services);
        self::assertSame(
            ['middleware.error', 'middleware.security.runtime', 'middleware.security', 'middleware.timing', 'middleware.cors'],
            $config['stack'],
        );
        $container = $this->createMock(Psr\Container\ContainerInterface::class);
        $logger = $this->createMock(Psr\Log\LoggerInterface::class);
        $container->method('get')->willReturn($logger);
        $errorFactory = $this->factory('middleware.error');
        $error = $errorFactory($container);
        self::assertInstanceOf(Zef\Middleware\GlobalErrorHandler::class, $error);
        $timingFactory = $this->factory('middleware.timing');
        self::assertInstanceOf(Zef\Middleware\TimingMiddleware::class, $timingFactory());
    }

    public function testBuildCorsFromEnvAllowlist(): void
    {
        $this->env('ZEF_APP_ENV', 'development');
        $this->env('ZEF_CORS_ORIGIN', 'https://app.example, https://other.example');
        $this->env('ZEF_CORS_ORIGIN_ANY', '0');
        $cors = $this->factory('middleware.cors')();
        self::assertInstanceOf(CorsMiddleware::class, $cors);

        $uri = $this->createMock(Psr\Http\Message\UriInterface::class);
        $request = $this->createMock(Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaderLine')->willReturn('https://app.example');
        $request->method('getUri')->willReturn($uri);
        $handler = $this->createMock(Psr\Http\Server\RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Zef\Framework\Http\Response(200, [], 'ok'));
        $response = $cors->process($request, $handler);
        self::assertSame('https://app.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testBuildCorsAnyBlockedInProduction(): void
    {
        $this->env('ZEF_APP_ENV', 'production');
        $this->env('ZEF_CORS_ORIGIN_ANY', '1');
        try {
            $this->factory('middleware.cors')();
            self::fail('wildcard CORS in production must throw');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testBuildCorsAnyAllowedInDevelopment(): void
    {
        $this->env('ZEF_APP_ENV', 'development');
        $this->env('ZEF_CORS_ORIGIN_ANY', '1');
        $cors = $this->factory('middleware.cors')();
        self::assertInstanceOf(CorsMiddleware::class, $cors);
        $uri = $this->createMock(Psr\Http\Message\UriInterface::class);
        $request = $this->createMock(Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaderLine')->willReturn('https://x.example');
        $request->method('getUri')->willReturn($uri);
        $handler = $this->createMock(Psr\Http\Server\RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Zef\Framework\Http\Response(200, [], 'ok'));
        $response = $cors->process($request, $handler);
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testBuildSecurityMiddlewareFromEnv(): void
    {
        $this->env('ZEF_SECURITY_HSTS', '1');
        $this->env('ZEF_SECURITY_CSP', '0');
        $security = $this->factory('middleware.security')();
        self::assertInstanceOf(SecurityHeadersMiddleware::class, $security);
    }

    public function testSecurityRuntimeFallbackStore(): void
    {
        $runtime = $this->factory('middleware.security.runtime')();
        self::assertInstanceOf(Zef\Framework\Security\SecurityRuntimeMiddleware::class, $runtime);
    }
}
