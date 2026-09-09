<?php

declare(strict_types=1);

namespace Zef\Framework\Container {
    use Psr\Container\ContainerInterface;
    use Zef\Framework\Exception\ServiceCircularDependencyException;

    final class ResolutionContext implements ContainerInterface
    {
        /** @var array<string,bool> */
        private array $loading = [];
        public function __construct(private readonly ContainerResolver $resolver, private readonly ?RequestScope $scope)
        {
        }
        #[\Override]
        public function get(string $id): mixed
        {
            return $this->resolver->resolveInContext($id, $this, $this->scope);
        }
        #[\Override]
        public function has(string $id): bool
        {
            return $this->resolver->hasInContext($id, $this->scope);
        }
        public function push(string $id): void
        {
            if (count($this->loading) >= $this->resolver->maxResolutionDepth()) {
                throw new \Zef\Framework\Exception\InvalidConfigurationException('Dependency resolution depth exceeds configured safety budget.');
            }
            if (isset($this->loading[$id])) {
                $chain = array_keys($this->loading);
                $chain[] = $id;
                throw new ServiceCircularDependencyException($chain);
            }
            $this->loading[$id] = true;
        }
        public function pop(string $id): void
        {
            unset($this->loading[$id]);
        }
        public function reset(): void
        {
            $this->loading = [];
        }
    }
}
