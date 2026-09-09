<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Exception\InvalidConfigurationException;

$pass = 0;
$fail = 0;
$check = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    if ($condition) { ++$pass; echo "PASS: {$message}\n"; return; }
    ++$fail; echo "FAIL: {$message}\n";
};
$throws = static function (string $class, callable $operation): bool {
    try { $operation(); } catch (Throwable $exception) { return $exception instanceof $class; }
    return false;
};
$isInstance = static function (mixed $value, string $class): bool {
    return $value instanceof $class;
};

$definition = ModuleDefinition::fromArray('Core', [
    'aliases' => ['primary' => 'core.service'],
    'requires' => ['Payments', 'payments', 'Search'],
    'feature_flag' => true,
]);
$check($definition->aliases === ['primary' => 'core.service'], 'aliases normalize from array');
$check($definition->dependencies === ['payments', 'search'], 'legacy requires normalize case-insensitively and deduplicate');
$check($definition->extensions === ['feature_flag' => true], 'extension keys remain outside core policy fields');
$check($definition->toArray()['dependencies'] === ['payments', 'search'], 'normalized dependencies serialize consistently');

$service = ServiceDefinition::fromArray('core.service', ['factory' => static fn(): null => null], 'Core');
$withService = ModuleDefinition::fromArray('Core', ['services' => ['core.service' => $service]]);
$check($isInstance($withService->services['core.service'], ServiceDefinition::class), 'service definitions remain typed');
$check($withService->services['core.service']->module === 'Core', 'service module context is preserved');
$check($throws(InvalidArgumentException::class, static fn() => ModuleDefinition::fromArray('Core', ['services' => 'bad'])), 'service collection must be an array');
$check($throws(InvalidArgumentException::class, static fn() => ModuleDefinition::fromArray('Core', ['aliases' => 'bad'])), 'alias collection must be an array');
$check($throws(InvalidArgumentException::class, static fn() => ModuleDefinition::fromArray('Core', ['dependencies' => [12]])), 'dependencies must contain strings');
$check($throws(InvalidArgumentException::class, static fn() => new ModuleDefinition('bad name')), 'module name validation is enforced');
$check($throws(InvalidArgumentException::class, static fn() => new ModuleDefinition('Core', aliases: ['' => 'target'])), 'empty alias validation is enforced');

/** @param array<string,mixed> $config */
$provider = static function (string $name, array $config): ConfigProviderInterface {
    /** @var array<string,mixed> $config */
    return new class($name, $config) implements ConfigProviderInterface {
        /** @param array<string,mixed> $config */
        public function __construct(private string $name, private array $config) {}
        #[\Override]
        public function getModuleName(): string { return $this->name; }
        /** @return array<string,mixed> */
        #[\Override]
        public function getConfig(): array { return $this->config; }
    };
};
$aggregator = new ConfigAggregator();
$aggregator->addProvider($provider('Core', ['enabled' => true]));
$check($throws(InvalidConfigurationException::class, static fn() => $aggregator->addProvider($provider('core', []))), 'provider names are case-insensitively unique');
$check($aggregator->merge() === ['core' => ['enabled' => true]], 'provider names normalize to lowercase on merge');
$check($aggregator->get('core.enabled') === true, 'nested configuration lookup works');
$check($aggregator->get('missing.value', 'fallback') === 'fallback', 'configuration lookup returns default');
$check($isInstance($aggregator->moduleDefinitions()['core'], ModuleDefinition::class), 'merged configuration produces typed module definitions');
$check($throws(LogicException::class, static fn() => $aggregator->addProvider($provider('later', []))), 'providers cannot be added after merge');

printf("Config policy characterization: %d pass, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
