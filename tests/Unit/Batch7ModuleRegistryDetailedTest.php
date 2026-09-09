<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Zef\Framework\Config\AbstractModule;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Config\ConfigProviderModule;
use Zef\Framework\Config\ModuleConfigProvider;
use Zef\Framework\Config\ModuleContext;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Config\ModuleInterface;
use Zef\Framework\Config\ModuleRegistry;
use Zef\Framework\Config\ModuleRegistrar;
use Zef\Framework\Exception\InvalidConfigurationException;

final class Batch7ModuleRegistryDetailedRegistrar implements ModuleRegistrar
{
    /** @var list<string> */
    public array $registered = [];

    #[Override]
    public function registerModule(string $module, ModuleDefinition|array $config): void
    {
        $this->registered[] = $module;
    }
}

/**
 * Detailed branch coverage for ModuleRegistry (all 12 methods), the
 * AbstractModule default lifecycle hooks and ModuleDefinition validation
 * edges. Deterministic: pure in-memory fixtures, no clock/env/random.
 */
final class Batch7ModuleRegistryDetailedTest extends TestCase
{
    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            #[Override]
            public function get(string $id): mixed
            {
                throw new RuntimeException("unexpected lookup: {$id}");
            }

            #[Override]
            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    private function registrar(): Batch7ModuleRegistryDetailedRegistrar
    {
        return new Batch7ModuleRegistryDetailedRegistrar();
    }

    /** A module that does NOT override the no-op lifecycle hooks.
     *  @param list<string> $deps */
    private function silentModule(string $name, array $deps = []): ModuleInterface
    {
        return new class ($name, $deps) extends AbstractModule {
            /** @param list<string> $deps */
            public function __construct(string $name, array $deps)
            {
                parent::__construct(new ModuleDefinition($name, dependencies: $deps));
            }
        };
    }

    public function testInvalidModuleNamesRejected(): void
    {
        $moduleWithName = static fn (string $override): ModuleInterface => new class ($override) extends AbstractModule {
            public function __construct(private readonly string $overrideName)
            {
                parent::__construct(new ModuleDefinition('valid-name'));
            }

            #[\Override]
            public function getName(): string
            {
                return $this->overrideName;
            }
        };
        foreach (['', 'bad name', '1starts-with-digit', 'has space'] as $name) {
            try {
                (new ModuleRegistry())->add($moduleWithName($name));
                self::fail("module '{$name}' must be rejected");
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testModuleDefinitionNameMismatchRejected(): void
    {
        $module = new class extends AbstractModule {
            public function __construct()
            {
                parent::__construct(new ModuleDefinition('defined-name'));
            }

            #[Override]
            public function getName(): string
            {
                return 'registered-name';
            }
        };
        try {
            (new ModuleRegistry())->add($module);
            self::fail('definition/name mismatch must throw');
        } catch (InvalidConfigurationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testAddProviderWrapsConfigProviderModule(): void
    {
        $provider = new class implements ConfigProviderInterface {
            #[Override]
            public function getModuleName(): string
            {
                return 'prov';
            }

            #[Override]
            public function getConfig(): array
            {
                return [];
            }
        };
        $registry = new ModuleRegistry();
        $registry->addProvider($provider);
        $modules = $registry->modules();
        self::assertCount(1, $modules);
        self::assertInstanceOf(ConfigProviderModule::class, $modules[0]);
        self::assertSame('prov', $modules[0]->getName());
        // providers() returns the underlying provider for ConfigProviderModule.
        $providers = $registry->providers();
        self::assertCount(1, $providers);
        self::assertSame($provider, $providers[0]);
    }

    public function testProvidersWrapsPlainModuleWithModuleConfigProvider(): void
    {
        $module = $this->silentModule('plain');
        $registry = new ModuleRegistry();
        $registry->add($module);
        $providers = $registry->providers();
        self::assertCount(1, $providers);
        self::assertInstanceOf(ModuleConfigProvider::class, $providers[0]);
        self::assertSame('plain', $providers[0]->getModuleName());
        self::assertArrayHasKey('dependencies', $providers[0]->getConfig());
    }

    public function testAddAfterRegistrationStartedThrows(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->silentModule('a'));
        $registry->registerAll($this->registrar(), $this->container());
        try {
            $registry->add($this->silentModule('b'));
            self::fail('add after registration must throw');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testCircularDependencyDetected(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->silentModule('x', ['y']));
        $registry->add($this->silentModule('y', ['x']));
        try {
            $registry->resolveOrder();
            self::fail('circular dependency must throw');
        } catch (InvalidConfigurationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testMissingDependencyDetected(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->silentModule('x', ['ghost']));
        try {
            $registry->resolveOrder();
            self::fail('missing dependency must throw');
        } catch (InvalidConfigurationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testRegisterAllIsIdempotent(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->silentModule('a'));
        $registrar = $this->registrar();
        $container = $this->container();
        $registry->registerAll($registrar, $container);
        $registry->registerAll($registrar, $container); // second call must no-op
        self::assertSame(['a'], $registrar->registered);
        self::assertTrue($registry->isRegistered());
        self::assertFalse($registry->isBooted());
        self::assertFalse($registry->isStarted());
    }

    public function testBootAllRequiresRegistrationAndIsIdempotent(): void
    {
        $registry = new ModuleRegistry();
        try {
            $registry->bootAll($this->container());
            self::fail('boot without registration must throw');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $registry->add($this->silentModule('a'));
        $registry->registerAll($this->registrar(), $this->container());
        $container = $this->container();
        $registry->bootAll($container);
        $registry->bootAll($container); // second call no-op
        self::assertTrue($registry->isBooted());
    }

    public function testStartAllRequiresBootAndIsIdempotent(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->silentModule('a'));
        try {
            $registry->startAll($this->container());
            self::fail('start without boot must throw');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $registry->registerAll($this->registrar(), $this->container());
        $registry->bootAll($this->container());
        $container = $this->container();
        $registry->startAll($container);
        $registry->startAll($container); // second call no-op
        self::assertTrue($registry->isStarted());
    }

    public function testShutdownAllWithoutRegistrationIsNoOp(): void
    {
        $registry = new ModuleRegistry();
        $registry->shutdownAll($this->container()); // must not throw
        self::assertFalse($registry->isBooted());
        $this->addToAssertionCount(1);
    }

    public function testShutdownAllResetsFlagsAndSwallowsThrowables(): void
    {
        $module = new class extends AbstractModule {
            public function __construct()
            {
                parent::__construct(new ModuleDefinition('boom'));
            }

            #[Override]
            public function shutdown(ModuleContext $context): void
            {
                throw new RuntimeException('shutdown exploded');
            }
        };
        $registry = new ModuleRegistry();
        $registry->add($module);
        $container = $this->container();
        $registry->registerAll($this->registrar(), $container);
        $registry->bootAll($container);
        $registry->startAll($container);
        $registry->shutdownAll($container); // exception swallowed
        self::assertFalse($registry->isStarted());
        self::assertFalse($registry->isBooted());
        self::assertTrue($registry->isRegistered());
    }

    public function testModulesReturnsValuesInInsertionOrder(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->silentModule('first'));
        $registry->add($this->silentModule('second'));
        $names = array_map(static fn (ModuleInterface $m): string => $m->getName(), $registry->modules());
        self::assertSame(['first', 'second'], $names);
    }

    public function testAbstractModuleDefaultLifecycleHooksRun(): void
    {
        $module = $this->silentModule('silent');
        $container = $this->container();
        $definition = $module->getDefinition();
        $context = new ModuleContext($definition, $container);
        self::assertSame('silent', $module->getName());
        self::assertSame($definition, $module->getDefinition());
        self::assertSame('silent', $context->name());
        self::assertSame($definition, $context->definition());
        self::assertSame($container, $context->container());
        // Default hooks are no-ops but must execute without error.
        $module->register($context);
        $module->boot($context);
        $module->start($context);
        $module->shutdown($context);
        $this->addToAssertionCount(1);
    }

    public function testModuleDefinitionDependencyValidationEdges(): void
    {
        try {
            new ModuleDefinition('m', dependencies: ['']);
            self::fail('empty dependency must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new ModuleDefinition('m', dependencies: ['bad name']);
            self::fail('invalid dependency name must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        // Duplicate + case-insensitive dependencies are normalized & deduped.
        $definition = new ModuleDefinition('m', dependencies: ['DepA', 'depa', 'DEPB']);
        self::assertSame(['depa', 'depb'], $definition->dependencies);
    }

    public function testModuleDefinitionFromArrayErrorBranches(): void
    {
        $cases = [
            ['m', ['services' => 'x']],
            ['m', ['aliases' => 'x']],
            ['m', ['routes' => 'x']],
            ['m', ['dependencies' => 'x']],
            ['m', ['services' => [42 => 'bad']]],
            ['m', ['services' => ['s' => ['factory' => 'nope']]]],
            ['m', ['aliases' => [42 => 'x']]],
            ['m', ['routes' => ['not-an-array']]],
            ['m', ['dependencies' => [42]]],
        ];
        foreach ($cases as [$name, $config]) {
            try {
                ModuleDefinition::fromArray($name, $config);
                self::fail('expected InvalidArgumentException');
            } catch (Throwable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testModuleDefinitionFromArrayRespectsRequiresAliasAndCase(): void
    {
        $definition = ModuleDefinition::fromArray('m', ['requires' => ['A'], 'routes' => []]);
        self::assertSame(['a'], $definition->dependencies);
    }
}
