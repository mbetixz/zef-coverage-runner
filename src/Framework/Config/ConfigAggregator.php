<?php

declare(strict_types=1);

namespace Zef\Framework\Config {

    use Zef\Framework\Exception\InvalidConfigurationException;

    final class ConfigAggregator
    {
        /** @var list<ConfigProviderInterface> */
        private array $providers = [];
        /** @var array<string,array<string,mixed>> */
        private array $merged = [];
        private bool $mergedReady = false;

        public function addProvider(ConfigProviderInterface $provider): void
        {
            if ($this->mergedReady) {
                throw new \LogicException('Cannot add provider after configuration has been merged.');
            }
            $module = $provider->getModuleName();
            if ($module === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $module) !== 1) {
                throw new InvalidConfigurationException("Invalid module name '{$module}'.");
            }
            $moduleKey = strtolower($module);
            foreach ($this->providers as $existing) {
                if (strtolower($existing->getModuleName()) === $moduleKey) {
                    throw new InvalidConfigurationException("Duplicate module/provider '{$module}' (case-insensitive collision).");
                }
            }
            $this->providers[] = $provider;
        }

        /** @return array<string,array<string,mixed>> */
        public function merge(): array
        {
            if ($this->mergedReady) {
                return $this->merged;
            }
            $this->merged = [];
            foreach ($this->providers as $provider) {
                $cfg = $provider->getConfig();
                $this->merged[strtolower($provider->getModuleName())] = $cfg;
            }
            $this->mergedReady = true;
            return $this->merged;
        }
        public function get(string $key, mixed $default = null): mixed
        {
            $cur = $this->merged;
            foreach (explode('.', $key) as $s) {
                if (!is_array($cur) || !array_key_exists($s, $cur)) {
                    return $default;
                } $cur = $cur[$s];
            } return $cur;
        }
        /** @return array<string,array<string,mixed>> */
        public function all(): array
        {
            return $this->merged;
        }
        /** @return list<ConfigProviderInterface> */
        public function providers(): array
        {
            return $this->providers;
        }

        /** @return array<string,ModuleDefinition> */
        public function moduleDefinitions(): array
        {
            $definitions = [];
            foreach ($this->merge() as $name => $config) {
                $definitions[$name] = ModuleDefinition::fromArray($name, $config);
            }
            return $definitions;
        }
    }
}
