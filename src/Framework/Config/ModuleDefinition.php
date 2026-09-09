<?php

declare(strict_types=1);

namespace Zef\Framework\Config {


    /**
     * Typed, immutable module configuration boundary.
     *
     * ModuleDefinition normalizes the legacy provider array without changing
     * ConfigProviderInterface or runtime execution semantics.
     */
    final readonly class ModuleDefinition
    {
        /** @var array<string,\Zef\Framework\Container\ServiceDefinition> */
        public array $services;
        /** @var array<string,string> */
        public array $aliases;
        /** @var list<\Zef\Framework\Router\RouteDefinition> */
        public array $routes;
        /** @var list<string> Case-insensitive module dependency names. */
        public array $dependencies;

        /**
         * @param array<int|string,mixed> $services
         * @param array<int|string,mixed> $aliases
         * @param array<int|string,mixed> $routes
         * @param array<string,mixed> $extensions
         * @param array<int|string,mixed> $dependencies
         */
        public function __construct(
            public string $name,
            array $services = [],
            array $aliases = [],
            array $routes = [],
            public array $extensions = [],
            array $dependencies = [],
        ) {
            $name = trim($name);
            if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $name) !== 1) {
                throw new \InvalidArgumentException("Invalid module name '{$name}'.");
            }
            foreach ($services as $id => $definition) {
                if (!is_string($id) || $id === '' || !$definition instanceof \Zef\Framework\Container\ServiceDefinition) {
                    throw new \InvalidArgumentException('Module services must map non-empty IDs to ServiceDefinition instances.');
                }
                if ($definition->id !== $id) {
                    throw new \InvalidArgumentException("Service definition ID '{$definition->id}' does not match registry key '{$id}'.");
                }
            }
            foreach ($aliases as $alias => $target) {
                if (!is_string($alias) || $alias === '' || !is_string($target) || $target === '') {
                    throw new \InvalidArgumentException('Module aliases must map non-empty strings to non-empty strings.');
                }
            }
            foreach ($dependencies as $dependency) {
                if (!is_string($dependency) || $dependency === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $dependency) !== 1) {
                    throw new \InvalidArgumentException('Module dependencies must contain valid module names.');
                }
            }
            $normalizedDependencies = [];
            foreach ($dependencies as $dependency) {
                $normalizedDependencies[] = strtolower($dependency);
            }
            /** @var list<string> $dependencies */
            $dependencies = array_values(array_unique($normalizedDependencies));
            foreach ($routes as $route) {
                if (!$route instanceof \Zef\Framework\Router\RouteDefinition) {
                    throw new \InvalidArgumentException('Module routes must contain RouteDefinition instances.');
                }
            }
            /** @var array<string,\Zef\Framework\Container\ServiceDefinition> $services */
            /** @var list<\Zef\Framework\Router\RouteDefinition> $routes */
            $this->services = $services;
            $this->aliases = $aliases;
            $this->routes = $routes;
            $this->dependencies = $dependencies;
        }

        /** @param array<string,mixed> $config */
        public static function fromArray(string $name, array $config): self
        {
            $servicesRaw = $config['services'] ?? [];
            $aliasesRaw = $config['aliases'] ?? [];
            $routesRaw = $config['routes'] ?? [];
            $dependenciesRaw = $config['dependencies'] ?? ($config['requires'] ?? []);
            if (!is_array($servicesRaw)) {
                throw new \InvalidArgumentException("Module '{$name}' services must be an array.");
            }
            if (!is_array($aliasesRaw)) {
                throw new \InvalidArgumentException("Module '{$name}' aliases must be an array.");
            }
            if (!is_array($routesRaw)) {
                throw new \InvalidArgumentException("Module '{$name}' routes must be an array.");
            }
            if (!is_array($dependenciesRaw)) {
                throw new \InvalidArgumentException("Module '{$name}' dependencies must be an array.");
            }

            $services = [];
            foreach ($servicesRaw as $id => $definition) {
                if (!is_string($id) || $id === '') {
                    throw new \InvalidArgumentException("Module '{$name}' has an invalid service ID.");
                }
                if ($definition instanceof \Zef\Framework\Container\ServiceDefinition) {
                    if ($definition->id !== $id) {
                        throw new \InvalidArgumentException("Service definition ID '{$definition->id}' does not match registry key '{$id}'.");
                    }
                    $services[$id] = $definition->module === $name
                        ? $definition
                        : new \Zef\Framework\Container\ServiceDefinition($definition->id, $definition->factory, $definition->dependencies, $name, $definition->lifetime, $definition->shared, $definition->lazy, $definition->tags);
                    continue;
                }
                if (!is_array($definition)) {
                    throw new \InvalidArgumentException("Service '{$id}' has an invalid definition.");
                }
                /** @var array<string,mixed> $definition */
                $services[$id] = \Zef\Framework\Container\ServiceDefinition::fromArray($id, $definition, $name);
            }

            $aliases = [];
            foreach ($aliasesRaw as $alias => $target) {
                if (!is_string($alias) || !is_string($target)) {
                    throw new \InvalidArgumentException("Module '{$name}' contains an invalid alias definition.");
                }
                $aliases[$alias] = $target;
            }

            $routes = [];
            foreach ($routesRaw as $route) {
                if ($route instanceof \Zef\Framework\Router\RouteDefinition) {
                    $routes[] = $route;
                    continue;
                }
                if (!is_array($route)) {
                    throw new \InvalidArgumentException("Module '{$name}' routes must contain arrays or RouteDefinition instances.");
                }
                /** @var array<string,mixed> $route */
                $routes[] = \Zef\Framework\Router\RouteDefinition::fromArray($route);
            }

            $dependencies = [];
            foreach ($dependenciesRaw as $dependency) {
                if (!is_string($dependency)) {
                    throw new \InvalidArgumentException("Module '{$name}' dependencies must contain strings.");
                }
                $dependencies[] = $dependency;
            }
            $extensions = $config;
            unset($extensions['services'], $extensions['aliases'], $extensions['routes'], $extensions['dependencies'], $extensions['requires']);
            return new self($name, $services, $aliases, $routes, $extensions, $dependencies);
        }

        /** @return array<string,mixed> */
        public function toArray(): array
        {
            $services = [];
            foreach ($this->services as $id => $definition) {
                $services[$id] = $definition;
            }
            $routes = $this->routes;
            $result = array_merge(
                ['services' => $services,'aliases' => $this->aliases,'routes' => $routes,'dependencies' => $this->dependencies],
                $this->extensions,
            );
            /** @var array<string,mixed> $result */
            return $result;
        }
    }

}
