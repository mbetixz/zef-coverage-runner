<?php

declare(strict_types=1);

namespace Zef\Framework\Container {

    final class ServiceRegistry
    {
        /** @var array<string,ServiceDefinition> */
        private array $definitions = [];
        /** @var array<string,string> */
        private array $aliases = [];
        /** @var array<string,mixed> */
        private array $instances = [];
        /** @var array<string,string|null> */
        private array $moduleOf = [];
        public function hasFactory(string $id): bool
        {
            return isset($this->definitions[$id]);
        }
        public function hasAlias(string $id): bool
        {
            return isset($this->aliases[$id]);
        }
        /** @return array<string,ServiceDefinition> */
        public function definitions(): array
        {
            return $this->definitions;
        }
        /** @return array<string,mixed> */
        public function factories(): array
        {
            /** @var array<string,mixed> $out */
            $out = [];
            foreach ($this->definitions as $id => $definition) {
                $out[$id] = $definition->factory;
            }
            return $out;
        }
        /** @return array<string,string> */
        public function aliases(): array
        {
            return $this->aliases;
        }
        /** @return array<string,list<string>> */
        public function depsOf(): array
        {
            /** @var array<string,list<string>> $out */
            $out = [];
            foreach ($this->definitions as $id => $definition) {
                /** @var list<string> $dependencies */
                $dependencies = $definition->dependencies;
                $out[$id] = $dependencies;
            }
            return $out;
        }
        /** @return array<string,string|null> */
        public function moduleOf(): array
        {
            return $this->moduleOf;
        }
        /** @return array<string,string> */
        public function lifetimeOf(): array
        {
            /** @var array<string,string> $out */
            $out = [];
            foreach ($this->definitions as $id => $definition) {
                $out[$id] = $definition->lifetime;
            }
            return $out;
        }
        public function hasInstance(string $id): bool
        {
            return array_key_exists($id, $this->instances);
        }
        public function instance(string $id): mixed
        {
            return $this->instances[$id];
        }
        public function setInstance(string $id, mixed $value): void
        {
            $this->instances[$id] = $value;
        }
        public function clearInstances(): void
        {
            $this->instances = [];
        }
        public function addDefinition(ServiceDefinition $definition): void
        {
            $this->definitions[$definition->id] = $definition;
            $this->moduleOf[$definition->id] = $definition->module;
        }
        /** @param array<int|string,mixed> $deps */
        public function addFactory(string $id, callable $factory, array $deps, ?string $module, string $lifetime): void
        {
            $this->addDefinition(new ServiceDefinition($id, $factory, array_values($deps), $module, $lifetime, $lifetime === ServiceLifetime::SINGLETON));
        }
        public function addAlias(string $alias, string $target, ?string $module): void
        {
            $this->aliases[$alias] = $target;
            $this->moduleOf[$alias] = $module;
        }
    }
}
