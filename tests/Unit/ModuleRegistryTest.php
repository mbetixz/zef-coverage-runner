<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Zef\Framework\Config\AbstractModule;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Config\ModuleInterface;
use Zef\Framework\Config\ModuleRegistry;
use Zef\Framework\Config\ModuleRegistrar;

final class ModuleRegistryTestContainer implements ContainerInterface
{
    #[\Override]
    public function get(string $id): mixed { throw new RuntimeException("unexpected container lookup: {$id}"); }
    #[\Override]
    public function has(string $id): bool { return false; }
}
final class ModuleRegistryTestRegistrar implements ModuleRegistrar
{
    /** @var list<string> */
    public array $registered = [];
    /** @param array<string,mixed>|ModuleDefinition $config */
    #[\Override]
    public function registerModule(string $module, ModuleDefinition|array $config): void { $this->registered[] = $module; }
}
final class ModuleRegistryTestModule extends AbstractModule
{
    /** @var list<string> */
    public array $events = [];
    /** @param array<int,string> $dependencies */
    public function __construct(string $name, array $dependencies = [])
    {
        parent::__construct(new ModuleDefinition($name, dependencies: $dependencies));
    }
    #[\Override]
    public function register(\Zef\Framework\Config\ModuleContext $context): void { $this->events[] = 'register'; }
    #[\Override]
    public function boot(\Zef\Framework\Config\ModuleContext $context): void { $this->events[] = 'boot'; }
    #[\Override]
    public function start(\Zef\Framework\Config\ModuleContext $context): void { $this->events[] = 'start'; }
    #[\Override]
    public function shutdown(\Zef\Framework\Config\ModuleContext $context): void { $this->events[] = 'shutdown'; }
}

final class ModuleRegistryTest extends TestCase
{
    public function testLegacyBehavior(): void
    {
        $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

        $a = new ModuleRegistryTestModule('a');
        $b = new ModuleRegistryTestModule('b', ['a']);
        $registry = new ModuleRegistry();
        $registry->add($b);
        $registry->add($a);
        $order = array_map(static fn(ModuleInterface $m): string => $m->getName(), $registry->resolveOrder());
        $assert($order === ['a', 'b'], 'dependency order is not topological');

        $registrar = new ModuleRegistryTestRegistrar();
        $container = new ModuleRegistryTestContainer();
        $registry->registerAll($registrar, $container);
        $assert($registrar->registered === ['a', 'b'], 'registration order drift');
        $registry->bootAll($container);
        $registry->startAll($container);
        $assert($a->events === ['register', 'boot', 'start'], 'module A lifecycle drift');
        $assert($b->events === ['register', 'boot', 'start'], 'module B lifecycle drift');
        $registry->shutdownAll($container);
        $assert($b->events === ['register', 'boot', 'start', 'shutdown'], 'module B reverse shutdown drift');
        $assert($a->events === ['register', 'boot', 'start', 'shutdown'], 'module A shutdown drift');

        $duplicateRegistry = new ModuleRegistry();
        $duplicateRegistry->add(new ModuleRegistryTestModule('A'));
        $duplicate = false;
        try { $duplicateRegistry->add(new ModuleRegistryTestModule('a')); } catch (\Zef\Framework\Exception\InvalidConfigurationException) { $duplicate = true; }
        $assert($duplicate, 'case-insensitive duplicate module was accepted');

        $late = false;
        try { $registry->add(new ModuleRegistryTestModule('c')); } catch (LogicException) { $late = true; }
        $assert($late, 'module was accepted after registration started');
        $this->addToAssertionCount(1);
    }
}
