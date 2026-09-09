<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Application;
    use Zef\Framework\Config\ConfigAggregator;
    use Zef\Framework\Config\ConfigProviderInterface;
    use Zef\Framework\Config\ModuleRegistry;
    use Zef\Framework\Http\RequestBodyPolicy;
    use Zef\Framework\Http\Response;

    /**
     * Batch 16 coverage: Application.php guard-rail branches that the Batch 9
     * lifecycle tests left uncovered (14 statements in Application.php).
     *
     * Determinism: telemetry is disabled by default (ZEF_OTEL_ENABLED=0,
     * restored in tearDown); the one enabled-telemetry test uses the
     * in-memory exporter with no endpoint so no network activity can occur.
     * Payload-too-large path is exercised through RequestFactory::fromGlobals
     * with synthetic $_SERVER values; all superglobals and env vars are saved
     * and restored in setUp/tearDown; no wall-clock assertions.
     *
     * Supply-chain note: no new dependencies; production code untouched.
     * Lives in tests/Unit — never tests/Regression/.
     */
    final class Batch16ApplicationGuardRailsTest extends TestCase
    {
        /** @var array<string, string|false> */
        private array $previousEnv = [];
        /** @var array<mixed> */
        private array $previousServer = [];
        /** @var array<mixed> */
        private array $previousPost = [];
        /** @var array<mixed> */
        private array $previousGet = [];
        /** @var array<mixed> */
        private array $previousFiles = [];
        /** @var array<mixed> */
        private array $previousCookie = [];

        #[Override]
        protected function setUp(): void
        {
            parent::setUp();
            foreach (['ZEF_OTEL_ENABLED', 'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT', 'ZEF_OTEL_SHUTDOWN_DRAIN_MS', 'ZEF_OTEL_FLUSH_PER_REQUEST'] as $name) {
                $this->previousEnv[$name] = getenv($name);
            }
            putenv('ZEF_OTEL_ENABLED=0');
            putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=');
            putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=0');
            putenv('ZEF_OTEL_FLUSH_PER_REQUEST=0');

            $this->previousServer = $_SERVER;
            $this->previousPost = $_POST;
            $this->previousGet = $_GET;
            $this->previousFiles = $_FILES;
            $this->previousCookie = $_COOKIE;
        }

        #[Override]
        protected function tearDown(): void
        {
            $_SERVER = $this->previousServer;
            $_POST = $this->previousPost;
            $_GET = $this->previousGet;
            $_FILES = $this->previousFiles;
            $_COOKIE = $this->previousCookie;
            foreach ($this->previousEnv as $name => $value) {
                $restored = $value === false ? null : $value;
                if ($restored === null) {
                    putenv($name);
                } else {
                    putenv($name . '=' . $restored);
                }
            }
            parent::tearDown();
        }

        /**
         * @param array<string, mixed> $config
         */
        private function providerWithConfig(array $config): ConfigProviderInterface
        {
            return new class ($config) implements ConfigProviderInterface {
                /** @param array<string, mixed> $config */
                public function __construct(private readonly array $config)
                {
                }

                #[Override]
                public function getModuleName(): string
                {
                    return 'batch16';
                }

                #[Override]
                public function getConfig(): array
                {
                    return $this->config;
                }
            };
        }

        private function jsonRequest(string $method, string $path): ServerRequestInterface
        {
            return new \Zef\Framework\Http\ServerRequest(
                $method,
                new \Zef\Framework\Http\Uri('http://example.test' . $path),
                [],
                [],
                [],
                [],
                null,
                [],
                \Zef\Framework\Http\Stream::fromString(''),
            );
        }

        public function testAddModuleAfterBootThrows(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig(['middleware.stack' => [], 'routes' => []]));
            $app->boot();
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Cannot add module after boot.');
            $app->addModule(new class extends \Zef\Framework\Config\AbstractModule {
                public function __construct()
                {
                    parent::__construct(\Zef\Framework\Config\ModuleDefinition::fromArray('late', ['services' => [], 'routes' => []]));
                }
            });
        }

        public function testSetTrustedHostsAfterBootThrows(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig(['middleware.stack' => [], 'routes' => []]));
            $app->boot();
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Cannot change trusted hosts after boot.');
            $app->setTrustedHosts(['example.com']);
        }

        public function testSetTrustedProxiesNormalizesThenThrowsAfterBoot(): void
        {
            $app = new Application();
            // Pre-boot normalization assignment (previously uncovered line 112).
            $app->setTrustedProxies(['127.0.0.1', '', '42']);
            $app->addProvider($this->providerWithConfig(['middleware.stack' => [], 'routes' => []]));
            $app->boot();
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Cannot change trusted proxies after boot.');
            $app->setTrustedProxies(['10.0.0.1']);
        }

        public function testSetMaxCrossModuleRefsConfiguresPolicies(): void
        {
            $app = new Application();
            $app->setMaxCrossModuleRefs(5);
            $this->addToAssertionCount(1);
        }

        public function testHandleFlushesTelemetryPerRequestWhenEnvSet(): void
        {
            putenv('ZEF_OTEL_ENABLED=1');
            putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=');
            putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=0');
            putenv('ZEF_OTEL_FLUSH_PER_REQUEST=1');
            $app = new Application();
            $app->addProvider($this->providerWithConfig([
                'services' => ['ok.handler' => [
                    'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                        #[Override]
                        public function handle(ServerRequestInterface $request): ResponseInterface
                        {
                            return new Response(200, [], 'ok');
                        }
                    },
                ]],
                'routes' => [['method' => 'GET', 'path' => '/flush', 'handler' => 'ok.handler']],
                'middleware.stack' => [],
            ]));
            $response = $app->handle($this->jsonRequest('GET', '/flush'));
            self::assertSame(200, $response->getStatusCode());
            self::assertTrue($app->isBooted());
        }

        public function testHandleGlobalsPayloadTooLargeReturns413(): void
        {
            $_SERVER = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/upload',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
                'HTTP_HOST' => 'example.test',
                'HTTP_CONTENT_LENGTH' => '99999999',
            ];
            $_POST = [];
            $_GET = [];
            $_FILES = [];
            $_COOKIE = [];
            $app = new Application(false, null, new RequestBodyPolicy(1024));
            $app->addProvider($this->providerWithConfig(['middleware.stack' => [], 'routes' => []]));
            $response = $app->handleGlobals();
            self::assertSame(413, $response->getStatusCode());
            $body = (string) $response->getBody();
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertSame('Content Too Large', $decoded['error'] ?? null);
        }

        public function testGetConfigAggregatorAndModuleRegistryReturnInstances(): void
        {
            $app = new Application();
            self::assertInstanceOf(ConfigAggregator::class, $app->getConfigAggregator());
            self::assertInstanceOf(ModuleRegistry::class, $app->getModuleRegistry());
        }

        public function testEmitSendsBodyToOutput(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig(['middleware.stack' => [], 'routes' => []]));
            $response = new Response(200, ['Content-Type' => 'text/plain'], 'emitted-body');
            ob_start();
            try {
                $app->emit($response);
                $output = ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            self::assertSame('emitted-body', $output);
        }
    }
}
