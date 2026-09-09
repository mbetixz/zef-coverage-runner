<?php

declare(strict_types=1);

namespace Zef\Framework\Container {
    use Zef\Framework\Exception\InvalidFactoryException;

    /**
         * Typed, immutable service configuration.
         *
         * This is the canonical representation used by the registry; legacy
         * array configuration is normalized at the module/bootstrap boundary.
         */
    final readonly class ServiceDefinition
    {
        /**
         * @param array<int|string,mixed> $dependencies
         * @param array<int|string,mixed> $tags
         * @throws \InvalidArgumentException when the definition is invalid.
         */
        public function __construct(
            public string $id,
            public mixed $factory,
            public array $dependencies = [],
            public ?string $module = null,
            public string $lifetime = ServiceLifetime::SINGLETON,
            public bool $shared = true,
            public bool $lazy = false,
            public array $tags = [],
        ) {
            if ($id === '') {
                throw new \InvalidArgumentException('Service definition ID must not be empty.');
            }
            if (!is_callable($factory)) {
                throw new \InvalidArgumentException("Service '{$id}' factory must be callable.");
            }
            ServiceLifetime::assert($lifetime);
            foreach ($dependencies as $dependency) {
                if (!is_string($dependency) || $dependency === '') {
                    throw new \InvalidArgumentException("Service '{$id}' dependencies must be non-empty strings.");
                }
            }
            foreach ($tags as $tag) {
                if (!is_string($tag) || $tag === '') {
                    throw new \InvalidArgumentException("Service '{$id}' tags must be non-empty strings.");
                }
            }
            if ($lifetime !== ServiceLifetime::SINGLETON && $shared) {
                throw new \InvalidArgumentException("Service '{$id}' cannot be shared unless lifetime is singleton.");
            }
        }

        /**
         * @param array<string,mixed> $config
         * @throws InvalidFactoryException when the legacy definition is invalid.
         */
        public static function fromArray(string $id, array $config, ?string $module = null): self
        {
            if (!array_key_exists('factory', $config) || !is_callable($config['factory'])) {
                throw new InvalidFactoryException("Factory for '{$id}' is invalid: callable factory required.");
            }
            $deps = $config['deps'] ?? [];
            if (!is_array($deps)) {
                throw new InvalidFactoryException("Factory for '{$id}' is invalid: dependencies must be an array.");
            }
            $lifetime = $config['lifetime'] ?? ServiceLifetime::SINGLETON;
            if (!is_string($lifetime)) {
                throw new InvalidFactoryException("Factory for '{$id}' is invalid: lifetime must be a string.");
            }
            $tags = $config['tags'] ?? [];
            if (!is_array($tags)) {
                throw new InvalidFactoryException("Factory for '{$id}' is invalid: tags must be an array.");
            }
            /** @var list<string> $deps */
            $deps = array_values($deps);
            /** @var list<string> $tags */
            $tags = array_values($tags);
            $sharedRaw = $config['shared'] ?? ($lifetime === ServiceLifetime::SINGLETON);
            return new self(
                id: $id,
                factory: $config['factory'],
                dependencies: $deps,
                module: $module,
                lifetime: $lifetime,
                shared: (bool) $sharedRaw,
                lazy: (bool) ($config['lazy'] ?? false),
                tags: $tags,
            );
        }
    }
}
