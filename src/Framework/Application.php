<?php

declare(strict_types=1);

namespace Zef\Framework {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Zef\Framework\Config\ConfigAggregator;
    use Zef\Framework\Config\ConfigProviderInterface;
    use Zef\Framework\Config\ModuleInterface;
    use Zef\Framework\Config\ModuleRegistry;
    use Zef\Framework\Container\Container;
    use Zef\Framework\Container\ServiceLifetime;
    use Zef\Framework\Http\RequestFactory;
    use Zef\Framework\Http\Response;
    use Zef\Framework\Router\Router;

    final class Application
    {
        private readonly ConfigAggregator $config;
        private readonly Container $container;
        private readonly Router $router;
        private readonly ModuleBootstrapper $bootstrapper;
        private readonly ModuleRegistry $modules;
        private readonly Dispatcher $dispatcher;
        private readonly ResponseEmitter $emitter;
        private ?MiddlewarePipeline $pipeline = null;
        private bool $booted = false;
        private bool $shutdown = false;

        /** @var list<string> */
        private array $trustedHosts = [];
        /** @var list<string> */
        private array $trustedProxies = [];
        private readonly \Zef\Framework\Http\RequestBodyPolicy $bodyPolicy;
        private readonly \Zef\Framework\Policy\ArchitecturePolicy $architecturePolicy;

        public function __construct(private readonly bool $debug = false, ?\Psr\Log\LoggerInterface $logger = null, ?\Zef\Framework\Http\RequestBodyPolicy $bodyPolicy = null, ?\Zef\Framework\Policy\ArchitecturePolicy $architecturePolicy = null, ?\Zef\Framework\Container\InitializationGuard $initializationGuard = null)
        {
            $this->config = new ConfigAggregator();
            $this->architecturePolicy = $architecturePolicy ?? new \Zef\Framework\Policy\ArchitecturePolicy();
            $this->container = new Container($debug, $this->architecturePolicy, $initializationGuard);
            $this->router = new Router(new \Zef\Framework\Validation\RouteConstraintValidator(), $this->architecturePolicy);
            $this->bootstrapper = new ModuleBootstrapper($this->container, $this->router);
            $this->modules = new ModuleRegistry();
            $this->dispatcher = new Dispatcher($this->router, $this->container);
            $this->emitter = new ResponseEmitter();
            $this->container->register(\Zef\Framework\Observability\Telemetry::class, static fn () => \Zef\Framework\Observability\Telemetry::fromEnvironment($logger), [], 'framework', ServiceLifetime::SINGLETON);
            $this->container->register(\Zef\Framework\Observability\TracerInterface::class, static function (\Psr\Container\ContainerInterface $c): \Zef\Framework\Observability\TracerInterface { /** @var \Zef\Framework\Observability\Telemetry $telemetry */ $telemetry = $c->get(\Zef\Framework\Observability\Telemetry::class);
                return $telemetry->tracer();
            }, [\Zef\Framework\Observability\Telemetry::class], 'framework', ServiceLifetime::SINGLETON);
            $this->container->register(\Zef\Framework\Observability\MeterInterface::class, static function (\Psr\Container\ContainerInterface $c): \Zef\Framework\Observability\MeterInterface { /** @var \Zef\Framework\Observability\Telemetry $telemetry */ $telemetry = $c->get(\Zef\Framework\Observability\Telemetry::class);
                return $telemetry->meter();
            }, [\Zef\Framework\Observability\Telemetry::class], 'framework', ServiceLifetime::SINGLETON);
            $this->container->register(\Zef\Framework\Event\EventDispatcher::class, static fn (): \Zef\Framework\Event\EventDispatcher => new \Zef\Framework\Event\EventDispatcher(), [], 'framework', ServiceLifetime::SINGLETON);
            $this->container->alias(\Zef\Framework\Event\EventBusInterface::class, \Zef\Framework\Event\EventDispatcher::class, 'framework');
            $this->container->register(\Zef\Framework\CQRS\InMemoryIdempotencyStore::class, static fn (): \Zef\Framework\CQRS\InMemoryIdempotencyStore => new \Zef\Framework\CQRS\InMemoryIdempotencyStore(), [], 'framework', ServiceLifetime::SINGLETON);
            $this->container->register(\Zef\Framework\CQRS\CommandBus::class, static function (\Psr\Container\ContainerInterface $c): \Zef\Framework\CQRS\CommandBus { /** @var \Zef\Framework\CQRS\InMemoryIdempotencyStore $store */ $store = $c->get(\Zef\Framework\CQRS\InMemoryIdempotencyStore::class);
                /** @var \Zef\Framework\Event\EventBusInterface $eventBus */ $eventBus = $c->get(\Zef\Framework\Event\EventBusInterface::class);
                return new \Zef\Framework\CQRS\CommandBus($store, 3600, $eventBus);
            }, [\Zef\Framework\CQRS\InMemoryIdempotencyStore::class,\Zef\Framework\Event\EventBusInterface::class], 'framework', ServiceLifetime::SINGLETON);
            $this->container->alias(\Zef\Framework\CQRS\CommandBusInterface::class, \Zef\Framework\CQRS\CommandBus::class, 'framework');
            $this->container->register(\Zef\Framework\CQRS\QueryBus::class, static fn (): \Zef\Framework\CQRS\QueryBus => new \Zef\Framework\CQRS\QueryBus(), [], 'framework', ServiceLifetime::SINGLETON);
            $this->container->alias(\Zef\Framework\CQRS\QueryBusInterface::class, \Zef\Framework\CQRS\QueryBus::class, 'framework');
            $this->container->register(\Zef\Framework\Observability\TelemetryLogger::class, static function (\Psr\Container\ContainerInterface $c): \Zef\Framework\Observability\TelemetryLogger { /** @var \Psr\Log\LoggerInterface $logger */ $logger = $c->get(\Psr\Log\LoggerInterface::class);
                /** @var \Zef\Framework\Observability\Telemetry $telemetry */ $telemetry = $c->get(\Zef\Framework\Observability\Telemetry::class);
                return new \Zef\Framework\Observability\TelemetryLogger($logger, $telemetry);
            }, [\Psr\Log\LoggerInterface::class], 'framework', ServiceLifetime::SINGLETON);
            $this->bodyPolicy = $bodyPolicy ?? new \Zef\Framework\Http\RequestBodyPolicy();
            $this->container->register(\Zef\Framework\Cache\SystemCacheClock::class, static fn (): \Zef\Framework\Cache\SystemCacheClock => new \Zef\Framework\Cache\SystemCacheClock(), [], 'framework', ServiceLifetime::SINGLETON);
            $this->container->alias(\Zef\Framework\Cache\CacheClockInterface::class, \Zef\Framework\Cache\SystemCacheClock::class, 'framework');
            $this->container->register(\Zef\Framework\Cache\InMemoryCacheStore::class, static function (\Psr\Container\ContainerInterface $c): \Zef\Framework\Cache\InMemoryCacheStore { /** @var \Zef\Framework\Cache\CacheClockInterface $clock */ $clock = $c->get(\Zef\Framework\Cache\CacheClockInterface::class);
                return new \Zef\Framework\Cache\InMemoryCacheStore(10000, $clock);
            }, [\Zef\Framework\Cache\CacheClockInterface::class], 'framework', ServiceLifetime::SINGLETON);
            $this->container->register(\Zef\Framework\Cache\InMemoryCache::class, static function (\Psr\Container\ContainerInterface $c): \Zef\Framework\Cache\InMemoryCache { /** @var \Zef\Framework\Cache\InMemoryCacheStore $store */ $store = $c->get(\Zef\Framework\Cache\InMemoryCacheStore::class);
                return new \Zef\Framework\Cache\InMemoryCache($store);
            }, [\Zef\Framework\Cache\InMemoryCacheStore::class], 'framework', ServiceLifetime::SINGLETON);
            $this->container->alias(\Zef\Framework\Cache\CacheInterface::class, \Zef\Framework\Cache\InMemoryCache::class, 'framework');
            $this->container->register(\Psr\Log\LoggerInterface::class, static fn () => $logger ?? new \Psr\Log\NullLogger(), [], 'framework', ServiceLifetime::SINGLETON);
        }
        public function addProvider(ConfigProviderInterface $provider): void
        {
            if ($this->booted) {
                throw new \LogicException('Cannot add provider after boot.');
            }$this->modules->addProvider($provider);
        }
        public function addModule(ModuleInterface $module): void
        {
            if ($this->booted) {
                throw new \LogicException('Cannot add module after boot.');
            }$this->modules->add($module);
        }
        /**
         * @param list<string> $hosts
         */
        public function setTrustedHosts(array $hosts): void
        {
            if ($this->booted) {
                throw new \LogicException('Cannot change trusted hosts after boot.');
            }
            $this->trustedHosts = array_values(array_filter(array_map(static fn (mixed $v): string => (string) $v, $hosts), static fn (string $v): bool => $v !== ''));
        }

        /**
         * @param list<string> $proxies
         */
        public function setTrustedProxies(array $proxies): void
        {
            if ($this->booted) {
                throw new \LogicException('Cannot change trusted proxies after boot.');
            }
            $this->trustedProxies = array_values(array_filter(array_map(static fn (mixed $v): string => (string) $v, $proxies), static fn (string $v): bool => $v !== ''));
        }
        public function setMaxCrossModuleRefs(int $limit): void
        {
            $this->container->configurePolicies($limit);
        }
        public function boot(): void
        {
            if ($this->booted) {
                return;
            }
            foreach ($this->modules->providers() as $p) {
                $this->config->addProvider($p);
            } $merged = $this->config->merge();
            $maxRefsValue = $this->config->get('framework.container.max_cross_module_refs', 0);
            $maxRefs = is_int($maxRefsValue) ? $maxRefsValue : (is_numeric($maxRefsValue) ? (int) $maxRefsValue : 0);
            if ($maxRefs < 0) {
                $maxRefs = 0;
            }
            $this->container->configurePolicies($maxRefs);
            /** @var \Zef\Framework\Event\EventDispatcher $eventBus */ $eventBus = $this->container->get(\Zef\Framework\Event\EventDispatcher::class);
            /** @var \Zef\Framework\CQRS\CommandBusInterface $commandBus */ $commandBus = $this->container->get(\Zef\Framework\CQRS\CommandBusInterface::class);
            $this->modules->registerAll($this->bootstrapper, $this->container);
            $eventBus->freeze();
            $commandBus->freeze();
            /** @var \Zef\Framework\CQRS\QueryBusInterface $queryBus */ $queryBus = $this->container->get(\Zef\Framework\CQRS\QueryBusInterface::class);
            $queryBus->freeze();
            $this->container->validateAndFreeze();
            $this->router->freeze();
            $this->container->warmSingletons();
            $this->modules->bootAll($this->container);
            $this->modules->startAll($this->container);
            $this->pipeline = new PipelineFactory($this->container, $this->config, $this->dispatcher)->build();
            $this->booted = true;
        }
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            if ($this->shutdown) {
                throw new \LogicException('Application has already been shut down.');
            }
            if (!$this->booted) {
                $this->boot();
            }
            $scope = $this->container->createRequestScope();
            /** @var \Zef\Framework\Observability\Telemetry $telemetry */
            $telemetry = $this->container->get(\Zef\Framework\Observability\Telemetry::class);
            // Trace propagation headers are read with getHeaderLine() so a
            // missing or multi-value header degrades to a single trimmed line
            // ('' when absent); Telemetry::extract() treats '' as no parent.
            $parent = $telemetry->extract($request->getHeaderLine('traceparent'), $request->getHeaderLine('tracestate'));
            $span = $telemetry->startSpan('zef.http.request', [
                'http.request.method' => $request->getMethod(),
                'url.path' => $request->getUri()->getPath(),
                'server.address' => $request->getUri()->getHost(),
            ], $parent);
            $request = $request->withAttribute('__zef_request_scope', $scope)->withAttribute('__zef_telemetry_span', $span)->withAttribute('__zef_trusted_proxies', $this->trustedProxies);
            $traceId = $span->getContext()->isValid() ? $span->getContext()->traceId : '';
            $startNs = hrtime(true);
            $this->recordLifecycle($telemetry, 'request.started', $traceId);
            try {
                $response = $this->pipeline?->handle($request) ?? new Response(500, ['Content-Type' => 'text/plain'], 'Application pipeline unavailable.');
                if (strtoupper($request->getMethod()) === 'HEAD') {
                    $response = $response->withBody(\Zef\Framework\Http\Stream::fromString(''));
                }
                $elapsed = (hrtime(true) - $startNs) / 1_000_000_000;
                $span->setAttribute('http.response.status_code', $response->getStatusCode())->setAttribute('zef.request.duration_seconds', $elapsed)->setStatus($response->getStatusCode() >= 500 ? 'ERROR' : 'OK');
                $telemetry->meter()->increment('zef.http.requests.total', 1, ['http.request.method' => $request->getMethod(),'http.response.status_code' => $response->getStatusCode()]);
                $telemetry->meter()->observe('zef.http.request.duration_seconds', $elapsed, ['http.request.method' => $request->getMethod()]);
                $this->recordLifecycle($telemetry, 'request.completed', $traceId);
                return $telemetry->isEnabled() ? $response->withHeader('traceparent', $span->getContext()->traceParent()) : $response;
            } catch (\Throwable $e) {
                $span->setStatus('ERROR', $e::class);
                $span->addEvent('exception', ['exception.type' => $e::class,'exception.message' => $e->getMessage()]);
                $telemetry->meter()->increment('zef.http.errors.total', 1, ['http.request.method' => $request->getMethod(),'exception.type' => $e::class]);
                $this->recordLifecycle($telemetry, 'request.failed', $traceId);
                throw $e;
            } finally {
                $span->end();
                if (filter_var((string) (getenv('ZEF_OTEL_FLUSH_PER_REQUEST') ?: '0'), FILTER_VALIDATE_BOOL)) {
                    $telemetry->flush();
                } $scope->close();
            }
        }
        private function recordLifecycle(\Zef\Framework\Observability\Telemetry $telemetry, string $event, string $traceId = ''): void
        {
            $attributes = ['event.name' => $event];
            if ($traceId !== '') {
                $attributes['trace_id'] = $traceId;
            }
            $telemetry->recordLog('INFO', $event, $attributes);
            $telemetry->meter()->increment('zef.lifecycle.events.total', 1, ['event.name' => $event]);
        }

        public function runtimeAfterRequest(): void
        {
        }
        public function shutdown(): void
        {
            if (!$this->booted || $this->shutdown) {
                return;
            }
            $this->shutdown = true;
            $this->modules->shutdownAll($this->container);
            try {
                $telemetry = $this->container->get(\Zef\Framework\Observability\Telemetry::class);
                if ($telemetry instanceof \Zef\Framework\Observability\Telemetry) {
                    $telemetry->shutdown();
                }
            } catch (\Throwable) {
            }
        }
        public function handleGlobals(): ResponseInterface
        {
            try {
                return $this->handle(RequestFactory::fromGlobals($this->trustedHosts, $this->trustedProxies, $this->bodyPolicy));
            } catch (\Zef\Framework\Exception\PayloadTooLargeException) {
                return new Response(413, ['Content-Type' => 'application/json'], json_encode(['error' => 'Content Too Large'], JSON_THROW_ON_ERROR));
            } catch (\InvalidArgumentException $e) {
                return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'Bad Request','message' => $this->debug ? $e->getMessage() : 'Invalid request.'], JSON_THROW_ON_ERROR));
            }
        }
        public function emit(ResponseInterface $response): void
        {
            $this->emitter->emit($response);
        }
        public function getContainer(): Container
        {
            return $this->container;
        } public function getRouter(): Router
        {
            return $this->router;
        } public function getConfigAggregator(): ConfigAggregator
        {
            return $this->config;
        } public function getModuleRegistry(): ModuleRegistry
        {
            return $this->modules;
        } public function getCommandBus(): \Zef\Framework\CQRS\CommandBusInterface
        { /** @var \Zef\Framework\CQRS\CommandBusInterface $bus */ $bus = $this->container->get(\Zef\Framework\CQRS\CommandBusInterface::class);
            return $bus;
        } public function getQueryBus(): \Zef\Framework\CQRS\QueryBusInterface
        { /** @var \Zef\Framework\CQRS\QueryBusInterface $bus */ $bus = $this->container->get(\Zef\Framework\CQRS\QueryBusInterface::class);
            return $bus;
        } public function isBooted(): bool
        {
            return $this->booted;
        }

        /** @return list<string> */
        public function getTrustedHosts(): array
        {
            return $this->trustedHosts;
        }
    }
}
