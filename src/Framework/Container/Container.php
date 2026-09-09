<?php

declare(strict_types=1);

namespace Zef\Framework\Container {
    use Psr\Container\ContainerInterface;
    use Zef\Framework\Exception\InvalidFactoryException;
    use Zef\Framework\Policy\ArchitecturePolicy;
    use Zef\Framework\Validation\DependencyGraphValidator;

    final class Container implements ContainerInterface
    {
        private readonly ServiceRegistry $registry;
        private readonly ServiceRegistrar $registrar;
        private readonly DependencyGraphValidator $graphValidator;
        private readonly ContainerResolver $resolver;
        private readonly ContainerCompiler $compiler;
        private bool $frozen = false;
        private int $maxCrossModuleRefs = 0;
        private readonly ArchitecturePolicy $policy;

        public function __construct(
            private readonly bool $debug = false,
            ?ArchitecturePolicy $policy = null,
            ?InitializationGuard $initializationGuard = null,
        ) {
            $this->policy = $policy ?? new ArchitecturePolicy();
            $this->registry = new ServiceRegistry();
            $this->registrar = new ServiceRegistrar($this->registry);
            $this->graphValidator = new DependencyGraphValidator();
            $this->compiler = new ContainerCompiler($this->graphValidator);
            $this->resolver = new ContainerResolver(
                $this->registry,
                $this->graphValidator,
                $initializationGuard ?? new FailFastInitializationGuard(),
                $this->policy->maxResolutionDepth,
            );
            $this->resolver->bind($this);
        }

        public function configurePolicies(int $maxCrossModuleRefs = 0): void
        {
            if ($this->frozen) {
                throw new \LogicException('Container is frozen.');
            }
            $this->maxCrossModuleRefs = max(0, $maxCrossModuleRefs);
        }

        /** @param array<int|string, mixed> $deps */
        public function register(
            string $id,
            callable $factory,
            array $deps = [],
            ?string $module = null,
            string $lifetime = ServiceLifetime::SINGLETON,
        ): void {
            if ($this->frozen) {
                throw new \LogicException('Container is frozen.');
            }
            if (count($this->registry->definitions()) >= $this->policy->maxServiceRegistrations) {
                throw new \Zef\Framework\Exception\InvalidConfigurationException(
                    'Service registration budget exceeded.',
                );
            }
            $this->registrar->register($id, $factory, $deps, $module, $lifetime);
        }

        public function registerDefinition(ServiceDefinition $definition): void
        {
            if ($this->frozen) {
                throw new \LogicException('Container is frozen.');
            }
            if (count($this->registry->definitions()) >= $this->policy->maxServiceRegistrations) {
                throw new \Zef\Framework\Exception\InvalidConfigurationException(
                    'Service registration budget exceeded.',
                );
            }
            if ($this->registry->hasFactory($definition->id) || $this->registry->hasAlias($definition->id)) {
                throw new InvalidFactoryException(
                    "Factory for '{$definition->id}' is invalid: service ID already registered.",
                );
            }
            $this->registry->addDefinition($definition);
        }

        public function alias(string $alias, string $target, ?string $module = null): void
        {
            if ($this->frozen) {
                throw new \LogicException('Container is frozen.');
            }
            $this->registrar->alias($alias, $target, $module);
        }

        public function validateAndFreeze(): void
        {
            $plan = $this->compiler->compile($this->registry, $this->maxCrossModuleRefs);
            $this->resolver->installPlan($plan);
            $this->frozen = true;
        }

        public function warmSingletons(): void
        {
            if (!$this->frozen) {
                throw new \LogicException('Container must be frozen before warming singletons.');
            }
            foreach ($this->registry->definitions() as $id => $definition) {
                if ($definition->lifetime === ServiceLifetime::SINGLETON && $definition->shared && !$definition->lazy) {
                    $this->get($id);
                }
            }
        }

        #[\Override]
        public function get(string $id): mixed
        {
            return $this->resolver->resolveRoot($id);
        }

        #[\Override]
        public function has(string $id): bool
        {
            return $this->resolver->hasInContext($id, null);
        }

        public function isDebug(): bool
        {
            return $this->debug;
        }

        public function createRequestScope(): RequestScope
        {
            return $this->resolver->createRequestScope();
        }

        public function reset(bool $clearSingletons = false): void
        {
            $scope = $this->resolver->createRequestScope();
            $scope->close();
            if ($clearSingletons) {
                $this->resolver->clearSingletons();
            }
        }

        /** @return list<string> */
        public function getRegisteredIds(): array
        {
            return array_values(array_unique(array_merge(
                array_keys($this->registry->factories()),
                array_keys($this->registry->aliases()),
            )));
        }

        /** @return array<string, string> */
        public function getAliasMap(): array
        {
            return $this->registry->aliases();
        }

        public function getRegistry(): ServiceRegistryView
        {
            return new ServiceRegistryView($this->registry);
        }

        public function isFrozen(): bool
        {
            return $this->frozen;
        }
    }
}
