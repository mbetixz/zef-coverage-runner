<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\App\Bootstrap;
use Zef\Framework\Application;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;

/**
 * Batch 10 coverage: the shipped application Bootstrap (Zef\App\Bootstrap,
 * previously 0%) - trusted-host defaults/env override and the full module
 * route map (Core '/', '/about'; Health '/health/live', '/health/ready';
 * Toko '/toko', '/toko/produk/{id:int}') plus 404 and 400 semantics.
 *
 * Determinism: telemetry disabled (ZEF_OTEL_ENABLED=0), ZEF_* env restored.
 */
final class Batch10BootstrapModulesTest extends TestCase
{
    private const array BOOTSTRAP_ENV = [
        'ZEF_TRUSTED_HOSTS',
        'ZEF_MAX_BODY_BYTES',
        'ZEF_OTEL_ENABLED',
        'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT',
        'ZEF_OTEL_SHUTDOWN_DRAIN_MS',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::BOOTSTRAP_ENV as $name) {
            putenv($name);
        }
        putenv('ZEF_OTEL_ENABLED=0');
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (self::BOOTSTRAP_ENV as $name) {
            putenv($name);
        }
        parent::tearDown();
    }

    private function handle(Application $app, string $path): int
    {
        $response = $app->handle(new ServerRequest('GET', new Uri('http://localhost' . $path, ['localhost'])));
        return $response->getStatusCode();
    }

    public function testDefaultTrustedHosts(): void
    {
        $app = Bootstrap::createApp(false);
        self::assertSame(['localhost', '127.0.0.1', '::1', 'zef.test'], $app->getTrustedHosts());
    }

    public function testTrustedHostsFromEnvironment(): void
    {
        putenv('ZEF_TRUSTED_HOSTS=api.example.test, .internal.test');
        $app = Bootstrap::createApp(false);
        self::assertSame(['api.example.test', '.internal.test'], $app->getTrustedHosts());
    }

    public function testShippedModuleRoutesResolve(): void
    {
        $app = Bootstrap::createApp(false);
        self::assertSame(200, $this->handle($app, '/'));
        self::assertSame(200, $this->handle($app, '/about'));
        self::assertSame(200, $this->handle($app, '/health/live'));
        self::assertSame(200, $this->handle($app, '/health/ready'));
        self::assertSame(200, $this->handle($app, '/toko'));
        self::assertSame(200, $this->handle($app, '/toko/produk/2'));
    }

    public function testUnknownAndInvalidRoutes(): void
    {
        $app = Bootstrap::createApp(false);
        self::assertSame(404, $this->handle($app, '/missing'));
        self::assertSame(400, $this->handle($app, '/toko/produk/abc'));
    }

    public function testProdukDetailBodyContainsCatalogue(): void
    {
        $app = Bootstrap::createApp(false);
        $response = $app->handle(new ServerRequest('GET', new Uri('http://localhost/toko/produk/2', ['localhost'])));
        self::assertStringContainsString('Mouse Wireless', (string) $response->getBody());
    }
}
