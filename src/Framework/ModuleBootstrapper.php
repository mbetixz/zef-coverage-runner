<?php

declare(strict_types=1);

namespace Zef\Framework {
    use Zef\Framework\Container\Container;
    use Zef\Framework\Exception\InvalidConfigurationException;
    use Zef\Framework\Router\Router;

    final class ModuleBootstrapper implements \Zef\Framework\Config\ModuleRegistrar
    {
        public function __construct(private readonly Container $container, private readonly Router $router)
        {
        }
        /** @param array<string,mixed>|\Zef\Framework\Config\ModuleDefinition $config */
        #[\Override]
        public function registerModule(string $module, array|\Zef\Framework\Config\ModuleDefinition $config): void
        {
            try {
                $definition = $config instanceof \Zef\Framework\Config\ModuleDefinition
                    ? $config
                    : \Zef\Framework\Config\ModuleDefinition::fromArray($module, $config);
            } catch (\Throwable $e) {
                throw new InvalidConfigurationException("Module '{$module}' has invalid definition: {$e->getMessage()}", 0, $e);
            }
            if (strtolower($definition->name) !== strtolower($module)) {
                throw new InvalidConfigurationException("Module definition name '{$definition->name}' does not match '{$module}'.");
            }
            $moduleDefinition = $definition;
            $services = $moduleDefinition->services;
            foreach ($services as $id => $serviceDefinition) {
                if ($serviceDefinition->module !== $module) {
                    $serviceDefinition = new \Zef\Framework\Container\ServiceDefinition($serviceDefinition->id, $serviceDefinition->factory, $serviceDefinition->dependencies, $module, $serviceDefinition->lifetime, $serviceDefinition->shared, $serviceDefinition->lazy, $serviceDefinition->tags);
                }
                $this->container->registerDefinition($serviceDefinition);
            }
            foreach ($moduleDefinition->aliases as $alias => $target) {
                $this->container->alias($alias, $target, $module);
            }
            foreach ($moduleDefinition->routes as $route) {
                $this->router->add($route->method, $route->path, $route->handler, $module, $route->priority);
            }
        }
    }
}
