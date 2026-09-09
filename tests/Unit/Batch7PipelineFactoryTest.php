<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Http\Response;
use Zef\Framework\MiddlewareDefinition;
use Zef\Framework\MiddlewarePipeline;
use Zef\Framework\PipelineFactory;

/**
 * Coverage for PipelineFactory, MiddlewarePipeline, MiddlewareDefinition
 * validation/error paths and ConfigProviderInterface wiring.
 * Deterministic: fixed registrations and config only.
 */
final class Batch7PipelineFactoryTest extends TestCase
{
    /** @param array<int|string,mixed> $stack */
    private function aggregator(array $stack): ConfigAggregator
    {
        $provider = new class ($stack) implements ConfigProviderInterface {
            /** @param array<int|string,mixed> $stack */
            public function __construct(private readonly array $stack)
            {
            }
            #[Override]
            public function getModuleName(): string
            {
                return 'middleware';
            }
            /** @return array<string,mixed> */
            #[Override]
            public function getConfig(): array
            {
                return ['stack' => $this->stack];
            }
        };
        $aggregator = new ConfigAggregator();
        $aggregator->addProvider($provider);
        $aggregator->merge();
        return $aggregator;
    }

    private function terminal(): RequestHandlerInterface
    {
        $terminal = $this->createMock(RequestHandlerInterface::class);
        $terminal->method('handle')->willReturn(new Response(200, [], 'ok'));
        return $terminal;
    }

    private function request(): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($this->createMock(UriInterface::class));
        return $request;
    }

    public function testPipelineFactoryBuildsStackInConfigOrder(): void
    {
        $container = new Container();
        $order = [];
        $first = $this->middleware(static function () use (&$order): void { $order[] = 'first'; });
        $second = $this->middleware(static function () use (&$order): void { $order[] = 'second'; });
        $container->register('mw.first', static fn () => $first);
        $container->register('mw.second', static fn () => $second);
        $container->validateAndFreeze();

        $factory = new PipelineFactory($container, $this->aggregator(['mw.first', 'mw.second']), $this->terminal());
        $pipeline = $factory->build();
        $pipeline->handle($this->request());

        self::assertSame(['first', 'second'], $order);
    }

    public function testPipelineFactoryAcceptsLegacyArrayEntries(): void
    {
        $container = new Container();
        $middleware = $this->middleware(null);
        $container->register('mw.svc', static fn () => $middleware);
        $container->validateAndFreeze();

        $factory = new PipelineFactory($container, $this->aggregator([['service' => 'mw.svc', 'priority' => 10]]), $this->terminal());
        $pipeline = $factory->build();
        self::assertSame(200, $pipeline->handle($this->request())->getStatusCode());
    }

    public function testPipelineFactoryThrowsWhenMiddlewareNotRegistered(): void
    {
        $container = new Container();
        $container->validateAndFreeze();
        $factory = new PipelineFactory($container, $this->aggregator(['mw.missing']), $this->terminal());
        try {
            $factory->build();
            self::fail('expected InvalidConfigurationException');
        } catch (InvalidConfigurationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testPipelineFactoryThrowsWhenServiceNotMiddleware(): void
    {
        $container = new Container();
        $container->register('mw.notmiddleware', static fn () => new stdClass());
        $container->validateAndFreeze();
        $factory = new PipelineFactory($container, $this->aggregator(['mw.notmiddleware']), $this->terminal());
        try {
            $factory->build();
            self::fail('expected InvalidConfigurationException');
        } catch (InvalidConfigurationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testPipelineFactoryThrowsOnMalformedEntry(): void
    {
        $container = new Container();
        $container->validateAndFreeze();
        $factory = new PipelineFactory($container, $this->aggregator([['priority' => 'x']]), $this->terminal());
        try {
            $factory->build();
            self::fail('expected InvalidConfigurationException');
        } catch (InvalidConfigurationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testPipelineFactoryNonArrayStackBecomesEmpty(): void
    {
        $container = new Container();
        $container->validateAndFreeze();
        $provider = new class implements ConfigProviderInterface {
            #[Override]
            public function getModuleName(): string
            {
                return 'middleware';
            }
            /** @return array<string,mixed> */
            #[Override]
            public function getConfig(): array
            {
                return ['stack' => 'not-an-array'];
            }
        };
        $aggregator = new ConfigAggregator();
        $aggregator->addProvider($provider);
        $aggregator->merge();

        $pipeline = (new PipelineFactory($container, $aggregator, $this->terminal()))->build();
        self::assertSame(200, $pipeline->handle($this->request())->getStatusCode());
    }

    public function testPipelineWithoutTerminalReturns500(): void
    {
        $pipeline = new MiddlewarePipeline([]);
        $response = $pipeline->handle($this->request());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Pipeline terminal missing.', (string) $response->getBody());
    }

    public function testPipelineWithTerminalInvokesIt(): void
    {
        $terminal = $this->createMock(RequestHandlerInterface::class);
        $terminal->method('handle')->willReturn(new Response(201, [], 'created'));
        $pipeline = new MiddlewarePipeline([], $terminal);
        self::assertSame(201, $pipeline->handle($this->request())->getStatusCode());
    }

    public function testMiddlewareDefinitionValidation(): void
    {
        try {
            new MiddlewareDefinition('');
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new MiddlewareDefinition('mw', group: '');
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new MiddlewareDefinition('mw', tags: ['', 'ok']);
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $definition = new MiddlewareDefinition('mw', 5, 'grp', ['a', 'b']);
        self::assertSame('mw', $definition->serviceId);
        self::assertSame(5, $definition->priority);
        self::assertSame('grp', $definition->group);
        self::assertSame(['a', 'b'], $definition->tags);
    }

    public function testMiddlewareDefinitionFromArrayErrorPaths(): void
    {
        foreach ([
            static fn () => MiddlewareDefinition::fromArray([]),
            static fn () => MiddlewareDefinition::fromArray(['service' => 'mw', 'priority' => []]),
            static fn () => MiddlewareDefinition::fromArray(['service' => 'mw', 'tags' => 'x']),
            static fn () => MiddlewareDefinition::fromArray(['service' => 'mw', 'group' => 42]),
            static fn () => MiddlewareDefinition::fromArray(['service' => 'mw', 'tags' => ['bad' => 5]]),
        ] as $case) {
            try {
                $case();
                self::fail('expected InvalidConfigurationException');
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
        $definition = MiddlewareDefinition::fromArray(['id' => 'mw', 'priority' => '7', 'group' => null]);
        self::assertSame('mw', $definition->serviceId);
        self::assertSame(7, $definition->priority);
    }

    public function testMiddlewareDefinitionFromLegacy(): void
    {
        $string = MiddlewareDefinition::fromLegacy('mw.legacy');
        self::assertSame('mw.legacy', $string->serviceId);
        self::assertSame(0, $string->priority);

        $array = MiddlewareDefinition::fromLegacy(['service' => 'mw.arr', 'priority' => 3, 'tags' => ['a', 'b']]);
        self::assertSame('mw.arr', $array->serviceId);
        self::assertSame(['a', 'b'], $array->tags);
    }

    /** @param (callable():void)|null $onHandle */
    private function middleware(?callable $onHandle): MiddlewareInterface
    {
        return new class ($onHandle) implements MiddlewareInterface {
            /** @var (callable():void)|null */
            private $onHandle;

            /** @param (callable():void)|null $onHandle */
            public function __construct($onHandle)
            {
                $this->onHandle = $onHandle;
            }
            #[Override]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                if ($this->onHandle !== null) {
                    ($this->onHandle)();
                }
                return $handler->handle($request);
            }
        };
    }
}
