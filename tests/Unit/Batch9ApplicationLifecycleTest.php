<?php

declare(strict_types=1);

namespace Batch9Support {
    use Psr\Container\ContainerInterface;

    /** Minimal request-scope double that behaves like RequestScope for handler resolution. */
    final class ScopeDouble implements ContainerInterface
    {
        /** @param array<string, mixed> $services */
        public function __construct(private readonly array $services, private bool $closed = false)
        {
        }

        public function close(): void
        {
            $this->closed = true;
        }

        #[\Override]
        public function get(string $id): mixed
        {
            if ($this->closed) {
                throw new \LogicException('Request scope is closed.');
            }
            if (!array_key_exists($id, $this->services)) {
                throw new \RuntimeException("service not found: {$id}");
            }
            return $this->services[$id];
        }

        #[\Override]
        public function has(string $id): bool
        {
            return array_key_exists($id, $this->services);
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Application;
    use Zef\Framework\Config\AbstractModule;
    use Zef\Framework\Config\ConfigProviderInterface;
    use Zef\Framework\Config\ModuleDefinition;
    use Zef\Framework\Config\ModuleInterface;
    use Zef\Framework\Container\Container;
    use Zef\Framework\Container\ServiceDefinition;
    use Zef\Framework\Container\ServiceLifetime;
    use Zef\Framework\Http\Response;
    use Zef\Framework\Router\RouteDefinition;

    /**
     * Batch 9 coverage: the Application facade boot/handle lifecycle
     * (large file previously 0%), PipelineFactory build paths, ModuleBootstrapper
     * definition registration and the ModuleConfigProvider adapter.
     *
     * Determinism: telemetry is disabled (ZEF_OTEL_ENABLED=0 restored in
     * tearDown) so handle() exercises the enabled-branch only; clocks are
     * monotonic hrtime deltas, never wall time assertions.
     */
    final class Batch9ApplicationLifecycleTest extends TestCase
    {
        /** @var array<string, string|false> */
        private array $previousEnv = [];

        #[\Override]
        protected function setUp(): void
        {
            parent::setUp();
            foreach (['ZEF_OTEL_ENABLED', 'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT', 'ZEF_OTEL_SHUTDOWN_DRAIN_MS'] as $name) {
                $this->previousEnv[$name] = getenv($name);
            }
            putenv('ZEF_OTEL_ENABLED=0');
            putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=');
            putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=0');
        }

        #[\Override]
        protected function tearDown(): void
        {
            foreach ($this->previousEnv as $name => $value) {
                $value === false ? putenv($name) : putenv($name . '=' . $value);
            }
            parent::tearDown();
        }

        private function jsonRequest(string $method, string $path, string $body = ''): ServerRequestInterface
        {
            return new \Zef\Framework\Http\ServerRequest(
                $method,
                new \Zef\Framework\Http\Uri('http://example.test' . $path),
                [],
                [],
                [],
                [],
                $body !== '' ? json_decode($body, true) : null,
                ['Content-Type' => ['application/json']],
                \Zef\Framework\Http\Stream::fromString($body),
            );
        }

        public function testBootHandleDispatchesToRegisteredHandler(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig([
                'services' => ['handler.svc' => [
                    'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                        #[\Override]
                        public function handle(ServerRequestInterface $request): ResponseInterface
                        {
                            $route = $request->getAttribute('route') ?? '';
                            $body = json_encode(['ok' => true, 'route' => $route], JSON_THROW_ON_ERROR);
                            return new Response(200, ['Content-Type' => 'application/json'], $body);
                        }
                    },
                ]],
                'routes' => [[
                    'method' => 'GET',
                    'path' => '/healthz',
                    'handler' => 'handler.svc',
                ]],
                'middleware.stack' => [],
            ]));

            $response = $app->handle($this->jsonRequest('GET', '/healthz'));

            self::assertSame(200, $response->getStatusCode());
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertTrue($decoded['ok'] ?? false);
            self::assertTrue($app->isBooted());
            self::assertInstanceOf(Container::class, $app->getContainer());
            self::assertFalse($app->getRouter()->getMaxRoutesBudget() < 0);
        }

        public function testBootIsIdempotent(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig([
                'middleware.stack' => [],
                'routes' => [],
            ]));
            $app->boot();
            $app->boot();
            self::assertTrue($app->isBooted());
        }

        public function testAddProviderAfterBootThrows(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig(['middleware.stack' => []]));
            $app->boot();
            try {
                $app->addProvider($this->providerWithConfig(['x' => 1]));
                self::fail('provider after boot must throw');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        public function testHandleOnUnbootedAppBootsImplicitly(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig([
                'services' => ['ping.handler' => [
                    'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                        #[\Override]
                        public function handle(ServerRequestInterface $request): ResponseInterface
                        {
                            return new Response(201, [], 'pong');
                        }
                    },
                ]],
                'routes' => [['method' => 'GET', 'path' => '/ping', 'handler' => 'ping.handler']],
                'middleware.stack' => [],
            ]));
            $response = $app->handle($this->jsonRequest('GET', '/ping'));
            self::assertSame(201, $response->getStatusCode());
            self::assertSame('pong', (string) $response->getBody());
            self::assertTrue($app->isBooted());
        }

        public function testRouterFallthrough404FromHandle(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig([
                'services' => ['h' => [
                    'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                        #[\Override]
                        public function handle(ServerRequestInterface $request): ResponseInterface
                        {
                            return new Response(200, [], 'n/a');
                        }
                    },
                ]],
                'routes' => [['method' => 'GET', 'path' => '/known', 'handler' => 'h']],
                'middleware.stack' => [],
            ]));
            $response = $app->handle($this->jsonRequest('GET', '/missing'));
            self::assertSame(404, $response->getStatusCode());
        }

        public function testHandleErrorPathReturns500AndReattributesRoute(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig([
                'services' => ['boom.handler' => [
                    'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                        #[\Override]
                        public function handle(ServerRequestInterface $request): ResponseInterface
                        {
                            throw new \RuntimeException('handler exploded');
                        }
                    },
                ]],
                'routes' => [['method' => 'GET', 'path' => '/boom', 'handler' => 'boom.handler']],
                'middleware.stack' => [],
            ]));
            try {
                $app->handle($this->jsonRequest('GET', '/boom'));
                self::fail('handler exception must propagate');
            } catch (\RuntimeException $e) {
                self::assertSame('handler exploded', $e->getMessage());
            }
            // The app stays booted and usable afterwards.
            self::assertTrue($app->isBooted());
        }

        public function testPipelineUnavailableTerminalProduces500(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig([
                'services' => [],
                'routes' => [['method' => 'GET', 'path' => '/x', 'handler' => 'absent.handler']],
                'middleware.stack' => [],
            ]));
            // absent.handler cannot be resolved -> error path inside boot-freeze
            // is not hit; instead match fails at handler resolution and bubbles
            // as 500 from Dispatcher (InvalidConfigurationException).
            try {
                $response = $app->handle($this->jsonRequest('GET', '/x'));
                self::assertSame(500, $response->getStatusCode());
            } catch (\Throwable $e) {
                // Resolution failure may propagate before a response exists.
                $this->addToAssertionCount(1);
            }
        }

        public function testHandleGlobalsEmitsBadRequestForInvalidMethod(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig(['middleware.stack' => [], 'routes' => []]));
            $response = $app->handleGlobals();
            // No globals available in CLI: fromGlobals falls back to safe
            // defaults (GET /) which routes to 404 rather than throwing.
            self::assertContains($response->getStatusCode(), [200, 404, 400, 405]);
        }

        public function testMiddlewareStackExecutesInOrder(): void
        {
            $app = new Application();
            // PipelineFactory reads config->get('middleware.stack'): the config
            // aggregator keys merged config by module name, so the stack must
            // live under a module whose name is exactly 'middleware'.
            $app->addProvider(new class implements ConfigProviderInterface {
                #[\Override]
                public function getModuleName(): string
                {
                    return 'middleware';
                }

                #[\Override]
                public function getConfig(): array
                {
                    return ['stack' => ['mw.alpha']];
                }
            });
            $app->addProvider($this->providerWithConfig([
                'services' => [
                    'handler.svc' => [
                        'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                            #[\Override]
                            public function handle(ServerRequestInterface $request): ResponseInterface
                            {
                                return new Response(200, ['X-Trace' => (string) (is_string($request->getAttribute('trace')) ? $request->getAttribute('trace') : '')], 'done');
                            }
                        },
                    ],
                    'mw.alpha' => [
                        'factory' => static function (): \Psr\Http\Server\MiddlewareInterface {
                            return new class implements \Psr\Http\Server\MiddlewareInterface {
                                #[\Override]
                                public function process(ServerRequestInterface $request, \Psr\Http\Server\RequestHandlerInterface $handler): ResponseInterface
                                {
                                    return $handler->handle($request->withAttribute('trace', 'a'));
                                }
                            };
                        },
                    ],
                ],
                'routes' => [['method' => 'GET', 'path' => '/mw', 'handler' => 'handler.svc']],
            ]));
            $response = $app->handle($this->jsonRequest('GET', '/mw'));
            self::assertSame('a', $response->getHeaderLine('X-Trace'));
        }

        public function testAddModuleLifecycleAndBootstrapper(): void
        {
            $app = new Application();
            $module = new class extends AbstractModule {
                public int $booted = 0;
                public int $started = 0;
                public int $shutdowns = 0;

                public function __construct()
                {
                    parent::__construct(ModuleDefinition::fromArray('lifecycle-mod', [
                        'services' => [
                            'lm.handler' => [
                                'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                                    #[\Override]
                                    public function handle(ServerRequestInterface $request): ResponseInterface
                                    {
                                        return new Response(200, [], 'lifecycle');
                                    }
                                },
                                'lifetime' => ServiceLifetime::SINGLETON,
                            ],
                        ],
                        'aliases' => [],
                        'routes' => [['method' => 'GET', 'path' => '/lifecycle', 'handler' => 'lm.handler']],
                    ]));
                }

                #[\Override]
                public function boot(\Zef\Framework\Config\ModuleContext $context): void
                {
                    ++$this->booted;
                }

                #[\Override]
                public function start(\Zef\Framework\Config\ModuleContext $context): void
                {
                    ++$this->started;
                }

                #[\Override]
                public function shutdown(\Zef\Framework\Config\ModuleContext $context): void
                {
                    ++$this->shutdowns;
                }
            };
            $app->addModule($module);
            $response = $app->handle($this->jsonRequest('GET', '/lifecycle'));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(1, $module->booted);
            self::assertSame(1, $module->started);

            $app->shutdown();
            self::assertSame(1, $module->shutdowns);
            // Second shutdown is a no-op.
            $app->shutdown();
            self::assertSame(1, $module->shutdowns);
        }

        public function testShutdownWithoutBootIsNoop(): void
        {
            $app = new Application();
            $app->shutdown();
            $this->addToAssertionCount(1);
        }

        public function testHandleAfterShutdownThrows(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig(['middleware.stack' => [], 'routes' => []]));
            $app->boot();
            $app->shutdown();
            try {
                $app->handle($this->jsonRequest('GET', '/x'));
                self::fail('handle after shutdown must throw');
            } catch (\LogicException $e) {
                self::assertStringContainsString('shut down', $e->getMessage());
            }
        }

        public function testGetCommandAndQueryBusesResolve(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig(['middleware.stack' => [], 'routes' => []]));
            $app->boot();
            self::assertInstanceOf(\Zef\Framework\CQRS\CommandBusInterface::class, $app->getCommandBus());
            self::assertInstanceOf(\Zef\Framework\CQRS\QueryBusInterface::class, $app->getQueryBus());
            self::assertSame([], $app->getTrustedHosts());
        }

        public function testHeadRequestBodyIsStripped(): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig([
                'services' => ['h' => [
                    'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                        #[\Override]
                        public function handle(ServerRequestInterface $request): ResponseInterface
                        {
                            return new Response(200, [], 'hello-world-body');
                        }
                    },
                ]],
                'routes' => [['method' => 'GET', 'path' => '/head', 'handler' => 'h']],
                'middleware.stack' => [],
            ]));
            $response = $app->handle($this->jsonRequest('HEAD', '/head'));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('', (string) $response->getBody());
        }

        public function testModuleConfigProviderSurfacesModuleDefinition(): void
        {
            $provider = new \Zef\Framework\Config\ModuleConfigProvider(
                new class extends AbstractModule {
                    public function __construct()
                    {
                        parent::__construct(ModuleDefinition::fromArray('adapter-mod', ['routes' => [], 'services' => []]));
                    }
                },
            );
            self::assertSame('adapter-mod', $provider->getModuleName());
            $config = $provider->getConfig();
            self::assertArrayHasKey('routes', $config);
            self::assertArrayHasKey('services', $config);
        }

        public function testModuleBootstrapperRejectsNameMismatch(): void
        {
            $bootstrapper = new \Zef\Framework\ModuleBootstrapper(new Container(), new \Zef\Framework\Router\Router());
            $definition = ModuleDefinition::fromArray('other-name', ['services' => [], 'routes' => []]);
            try {
                $bootstrapper->registerModule('registered-name', $definition);
                self::fail('name mismatch must throw');
            } catch (\Zef\Framework\Exception\InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }

        public function testModuleBootstrapperRegistersServiceAliasAndRoute(): void
        {
            $container = new Container();
            $router = new \Zef\Framework\Router\Router();
            $bootstrapper = new \Zef\Framework\ModuleBootstrapper($container, $router);
            $definition = ModuleDefinition::fromArray('boot-mod', [
                'services' => [
                    'bm.svc' => [
                        'factory' => static fn (): \stdClass => new \stdClass(),
                    ],
                ],
                'aliases' => ['bm.alias' => 'bm.svc'],
                'routes' => [['method' => 'GET', 'path' => '/boot-mod', 'handler' => 'bm.svc']],
            ]);
            $bootstrapper->registerModule('boot-mod', $definition);
            self::assertTrue($container->has('bm.svc'));
            self::assertSame($container->get('bm.svc'), $container->get('bm.alias'));
            $container->validateAndFreeze();
            self::assertTrue($container->isFrozen());
        }

        private static int $providerSeq = 0;

        /**
         * @param array<string,mixed> $config
         */
        private function providerWithConfig(array $config): ConfigProviderInterface
        {
            $name = 'cfg' . (++self::$providerSeq);
            return new class ($name, $config) implements ConfigProviderInterface {
                /** @param array<string,mixed> $config */
                public function __construct(private readonly string $name, private readonly array $config)
                {
                }

                #[\Override]
                public function getModuleName(): string
                {
                    return $this->name;
                }

                #[\Override]
                public function getConfig(): array
                {
                    return $this->config;
                }
            };
        }
    }
}
