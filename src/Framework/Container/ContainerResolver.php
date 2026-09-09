<?php

declare(strict_types=1);

namespace Zef\Framework\Container {
    use Psr\Container\ContainerInterface;
    use Zef\Framework\Exception\ServiceNotFoundException;
    use Zef\Framework\Exception\ServiceResolutionException;
    use Zef\Framework\Validation\DependencyGraphValidator;

    final class ContainerResolver
    {
        private ?ContainerInterface $rootContainer = null;
        /** @var \WeakMap<RequestScope,array<string,mixed>> */
        private \WeakMap $scopeInstances;
        private readonly int $resolutionDepthLimit;
        private ?CompiledContainerPlan $plan = null;
        public function __construct(private readonly ServiceRegistry $registry, private readonly DependencyGraphValidator $graphValidator, private readonly InitializationGuard $initializationGuard = new FailFastInitializationGuard(), int $resolutionDepthLimit = 256)
        {
            $this->resolutionDepthLimit = max(1, $resolutionDepthLimit);
            $this->scopeInstances = new \WeakMap();
        }
        public function bind(ContainerInterface $container): void
        {
            $this->rootContainer = $container;
        }
        public function installPlan(CompiledContainerPlan $plan): void
        {
            $this->plan = $plan;
        }
        public function maxResolutionDepth(): int
        {
            return $this->resolutionDepthLimit;
        }
        public function createRequestScope(): RequestScope
        {
            $scope = new RequestScope($this);
            $this->scopeInstances[$scope] = [];
            return $scope;
        }
        public function releaseScope(RequestScope $scope): void
        {
            unset($this->scopeInstances[$scope]);
        }
        public function clearSingletons(): void
        {
            $this->registry->clearInstances();
        }
        public function resolveRoot(string $id): mixed
        {
            if ($this->rootContainer === null) {
                throw new \LogicException('Container resolver is not bound.');
            }$ctx = new ResolutionContext($this, null);
            try {
                return $ctx->get($id);
            } catch (\LogicException $e) {
                throw new ServiceResolutionException($id, $e->getMessage(), $e);
            }
        }
        public function resolveInContext(string $id, ResolutionContext $ctx, ?RequestScope $scope): mixed
        {
            $plan = $this->plan;
            if ($plan === null) {
                $canonical = $this->graphValidator->resolveAlias($id, $this->registry->aliases());
                /** @var ServiceDefinition|null $definition */
                $definition = $this->registry->definitions()[$canonical] ?? null;
                if ($definition === null) {
                    throw new ServiceNotFoundException($id, is_string($this->registry->moduleOf()[$id] ?? null) ? $this->registry->moduleOf()[$id] : null);
                }
                /** @var list<string> $dependencies */
                $dependencies = $definition->dependencies;
            } else {
                $canonical = $plan->canonical($id);
                if ($canonical === null) {
                    throw new ServiceNotFoundException($id, is_string($this->registry->moduleOf()[$id] ?? null) ? $this->registry->moduleOf()[$id] : null);
                }
                /** @var ServiceDefinition|null $definition */
                $definition = $plan->definitions[$canonical] ?? null;
                if ($definition === null) {
                    throw new ServiceNotFoundException($id, is_string($this->registry->moduleOf()[$id] ?? null) ? $this->registry->moduleOf()[$id] : null);
                }
                $dependencies = $plan->dependenciesOf($canonical);
            }
            $lifetime = $definition->lifetime;
            $shared = $definition->shared;
            if ($lifetime === ServiceLifetime::SINGLETON && $shared && $this->registry->hasInstance($canonical)) {
                return $this->registry->instance($canonical);
            }
            if ($lifetime === ServiceLifetime::REQUEST) {
                if ($scope === null || $scope->isClosed()) {
                    throw new \LogicException("Request-scoped service '{$canonical}' resolved outside a request scope.");
                }if ($this->scopeHas($scope, $canonical)) {
                    return $this->scopeGet($scope, $canonical);
                }
            }
            $ctx->push($canonical);
            try {
                $deps = [];
                foreach ($dependencies as $dep) {
                    $deps[] = $ctx->get($dep);
                }
                if (!is_callable($definition->factory)) {
                    throw new ServiceResolutionException($canonical, 'factory is not callable.');
                }
                $factory = $definition->factory;
                try {
                    $instance = $this->initializationGuard->synchronized($canonical, fn () => $factory($ctx, ...$deps));
                } catch (\Throwable $e) {
                    if ($e instanceof ServiceResolutionException) {
                        throw $e;
                    }throw new ServiceResolutionException($canonical, $e->getMessage(), $e);
                }
                if ($instance === null) {
                    throw new ServiceResolutionException($canonical, 'factory returned null.');
                }
                if ($lifetime === ServiceLifetime::SINGLETON && $shared) {
                    $this->registry->setInstance($canonical, $instance);
                } elseif ($lifetime === ServiceLifetime::REQUEST && $scope !== null) {
                    $this->scopeSet($scope, $canonical, $instance);
                }
                return $instance;
            } finally {
                $ctx->pop($canonical);
            }
        }
        private function scopeHas(RequestScope $scope, string $id): bool
        {
            return isset($this->scopeInstances[$scope]) && array_key_exists($id, $this->scopeInstances[$scope]);
        }
        private function scopeGet(RequestScope $scope, string $id): mixed
        {
            return $this->scopeInstances[$scope][$id];
        }
        private function scopeSet(RequestScope $scope, string $id, mixed $value): void
        {
            /** @var array<string,mixed> $state */
            $state = $this->scopeInstances[$scope] ?? [];
            $state[$id] = $value;
            $this->scopeInstances[$scope] = $state;
        }
        public function scopeHasPublic(RequestScope $scope, string $id): bool
        {
            return $this->scopeHas($scope, $id);
        }
        public function scopeGetPublic(RequestScope $scope, string $id): mixed
        {
            return $this->scopeGet($scope, $id);
        }
        public function scopeSetPublic(RequestScope $scope, string $id, mixed $value): void
        {
            $this->scopeSet($scope, $id, $value);
        }
        public function hasInContext(string $id, ?RequestScope $scope): bool
        {
            try {
                if ($this->plan !== null) {
                    return $this->plan->canonical($id) !== null;
                } $canonical = $this->graphValidator->resolveAlias($id, $this->registry->aliases());
                return isset($this->registry->lifetimeOf()[$canonical]) && $this->registry->hasFactory($canonical);
            } catch (\Throwable) {
                return false;
            }
        }
    }
}
