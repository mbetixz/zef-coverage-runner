<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Config\ConfigurationGovernance;
use Zef\Framework\Config\ConfigurationSnapshot;
use Zef\Framework\Config\EnvironmentSecretProvider;
use Zef\Framework\Config\ModuleConfigProvider;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Config\SecretProviderInterface;
use Zef\Framework\Config\SecretValue;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Exception\InvalidConfigurationException;

final class B3ConfigTestProvider implements ConfigProviderInterface
{
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
        /** @var array<string,mixed> $config */
        $config = $this->config;
        return $config;
    }
}

final class ConfigDetailedTest extends TestCase
{
    private function assertThrows(string $class, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if ($e instanceof $class) {
                $this->addToAssertionCount(1);
                return;
            }
            self::fail(sprintf('Expected %s, got %s: %s', $class, $e::class, $e->getMessage()));
        }
        self::fail(sprintf('Expected %s to be thrown, nothing was thrown.', $class));
    }

    public function testConfigAggregatorMergeAndGet(): void
    {
        $agg = new ConfigAggregator();
        self::assertSame([], $agg->providers());
        self::assertSame([], $agg->all());
        self::assertSame('dflt', $agg->get('missing.key', 'dflt'));
        self::assertNull($agg->get('missing.key'));

        $agg->addProvider(new B3ConfigTestProvider('Core', ['debug' => false, 'nested' => ['a' => 1]]));
        $agg->addProvider(new B3ConfigTestProvider('extra', ['b' => 2]));
        $merged = $agg->merge();
        self::assertArrayHasKey('core', $merged);
        self::assertArrayHasKey('extra', $merged);
        self::assertTrue($agg->all() === $merged, 'merge cached after ready');
        self::assertSame(1, $agg->get('core.nested.a'));
        self::assertNull($agg->get('core.nested.missing'));
        self::assertSame(false, $agg->get('core.debug'));
    }

    public function testConfigAggregatorRejectsDuplicateProvider(): void
    {
        $agg = new ConfigAggregator();
        $agg->addProvider(new B3ConfigTestProvider('core', []));
        self::assertThrows(InvalidConfigurationException::class, static fn () => $agg->addProvider(new B3ConfigTestProvider('CORE', [])));
        self::assertThrows(InvalidConfigurationException::class, static fn () => $agg->addProvider(new B3ConfigTestProvider('bad name', [])));
    }

    public function testConfigAggregatorAddAfterMergeThrows(): void
    {
        $agg = new ConfigAggregator();
        $agg->addProvider(new B3ConfigTestProvider('core', []));
        $agg->merge();
        self::assertThrows(\LogicException::class, static fn () => $agg->addProvider(new B3ConfigTestProvider('late', [])));
    }

    public function testConfigAggregatorModuleDefinitions(): void
    {
        $agg = new ConfigAggregator();
        $f = static fn () => new stdClass();
        $agg->addProvider(new B3ConfigTestProvider('core', [
            'services' => ['core.svc' => ['factory' => $f]],
            'aliases' => ['c' => 'core.svc'],
        ]));
        $defs = $agg->moduleDefinitions();
        self::assertArrayHasKey('core', $defs);
        self::assertInstanceOf(ModuleDefinition::class, $defs['core']);
        self::assertArrayHasKey('core.svc', $defs['core']->services);
        self::assertSame('core', $defs['core']->name);
    }

    public function testModuleConfigProvider(): void
    {
        $def = new ModuleDefinition('demo', ['demo.svc' => new ServiceDefinition('demo.svc', static fn () => new stdClass())]);
        $provider = new ModuleConfigProvider(new B3FakeModule($def));
        self::assertSame('demo', $provider->getModuleName());
        $cfg = $provider->getConfig();
        /** @var array<string,mixed> $cfg */
        self::assertArrayHasKey('services', $cfg);
        /** @var array<string,mixed> $services */
        $services = $cfg['services'];
        self::assertArrayHasKey('demo.svc', $services);
    }

    public function testConfigurationGovernance(): void
    {
        $gov = new ConfigurationGovernance();
        self::assertNull($gov->current());
        $gov->addValidator('debug-bool', static function (array $values): void {
            if (!is_bool($values['debug'] ?? null)) {
                throw new \RuntimeException('debug must be bool');
            }
        });
        $snap = $gov->publish(['debug' => true], 3);
        self::assertInstanceOf(ConfigurationSnapshot::class, $snap);
        self::assertSame(3, $snap->version);
        self::assertTrue($snap->values['debug']);
        self::assertSame($snap, $gov->current());
        // Adding a validator after publication throws.
        self::assertThrows(\LogicException::class, static fn () => $gov->addValidator('x', static fn () => null));
        // publish again returns new snapshot.
        $snap2 = $gov->publish(['debug' => false], 4);
        self::assertSame(4, $snap2->version);
    }

    public function testConfigurationGovernanceValidatorFailureWrapped(): void
    {
        $gov = new ConfigurationGovernance();
        $gov->addValidator('strict', static function (array $values): void {
            if (($values['mode'] ?? '') !== 'ok') {
                throw new \RuntimeException('bad mode');
            }
        });
        self::assertThrows(\InvalidArgumentException::class, static fn () => $gov->publish(['mode' => 'nope'], 1));
        $gov2 = new ConfigurationGovernance();
        self::assertThrows(\InvalidArgumentException::class, static fn () => $gov2->addValidator('bad name!', static fn () => null));
    }

    public function testConfigurationSnapshotVersionValidation(): void
    {
        self::assertThrows(\InvalidArgumentException::class, static fn () => new ConfigurationSnapshot([], 0));
        $s = new ConfigurationSnapshot(['k' => 'v'], 1);
        self::assertSame('v', $s->values['k']);
    }

    public function testSecretValue(): void
    {
        $secret = new SecretValue('topsecret');
        self::assertSame('topsecret', $secret->reveal());
        self::assertSame('[REDACTED]', (string) $secret);
        self::assertThrows(\InvalidArgumentException::class, static fn () => new SecretValue(''));
        self::assertThrows(\InvalidArgumentException::class, static fn () => new SecretValue(str_repeat('x', 4097)));
    }

    public function testEnvironmentSecretProvider(): void
    {
        $provider = new EnvironmentSecretProvider();
        putenv('ZEF_TEST_SECRET_ABC=envvalue');
        try {
            $secret = $provider->get('ZEF_TEST_SECRET_ABC');
            self::assertNotNull($secret);
            self::assertSame('envvalue', $secret->reveal());
            self::assertNull($provider->get('ZEF_TEST_SECRET_DOES_NOT_EXIST'));
        } finally {
            putenv('ZEF_TEST_SECRET_ABC');
        }
        self::assertThrows(\InvalidArgumentException::class, static fn () => $provider->get('lowercase'));
        self::assertThrows(\InvalidArgumentException::class, static fn () => $provider->get('1BAD'));
    }

    public function testSecretsCompatibilityRequires(): void
    {
        // Config\Secrets.php is a compatibility aggregate that require_once's the
        // per-type files; the classes/interfaces themselves are autoloadable.
        self::assertTrue(class_exists(SecretValue::class));
        self::assertTrue(interface_exists(SecretProviderInterface::class));
        self::assertTrue(class_exists(EnvironmentSecretProvider::class));
        // And Config.php aggregate loads the rest.
        self::assertTrue(class_exists(ConfigAggregator::class));
        self::assertTrue(class_exists(ConfigurationGovernance::class));
        self::assertTrue(class_exists(ModuleDefinition::class));
        self::assertTrue(interface_exists(ConfigProviderInterface::class));
    }
}

final class B3FakeModule implements \Zef\Framework\Config\ModuleInterface
{
    public function __construct(private readonly ModuleDefinition $definition)
    {
    }

    #[\Override]
    public function getName(): string
    {
        return $this->definition->name;
    }

    #[\Override]
    public function getDefinition(): ModuleDefinition
    {
        return $this->definition;
    }

    #[\Override]
    public function register(\Zef\Framework\Config\ModuleContext $context): void
    {
    }

    #[\Override]
    public function boot(\Zef\Framework\Config\ModuleContext $context): void
    {
    }

    #[\Override]
    public function start(\Zef\Framework\Config\ModuleContext $context): void
    {
    }

    #[\Override]
    public function shutdown(\Zef\Framework\Config\ModuleContext $context): void
    {
    }
}
