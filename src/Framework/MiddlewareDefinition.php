<?php

declare(strict_types=1);

namespace Zef\Framework {
    use Zef\Framework\Exception\InvalidConfigurationException;

    /**
     * Canonical typed representation for middleware pipeline entries.
     * Legacy string/array configuration is normalized at the pipeline boundary.
     */
    final readonly class MiddlewareDefinition
    {
        /**
         * @param list<string> $tags Runtime-validated non-empty strings.
         */
        public function __construct(
            public string $serviceId,
            public int $priority = 0,
            public ?string $group = null,
            public array $tags = [],
        ) {
            if ($serviceId === '') {
                throw new \InvalidArgumentException('Middleware service ID must not be empty.');
            }
            if ($group !== null && $group === '') {
                throw new \InvalidArgumentException('Middleware group must not be empty when provided.');
            }
            foreach ($tags as $tag) {
                // Runtime guard kept: PHPStan narrows $tag to string via the
                // @param docblock, but callers may bypass static analysis.
                if (!is_string($tag) || $tag === '') { // @phpstan-ignore function.alreadyNarrowedType
                    throw new \InvalidArgumentException('Middleware tags must be non-empty strings.');
                }
            }
        }

        /**
         * @param array<string,mixed> $config
         */
        public static function fromArray(array $config): self
        {
            $service = $config['service'] ?? $config['id'] ?? null;
            if (!is_string($service) || $service === '') {
                throw new InvalidConfigurationException('Middleware definition requires a non-empty service ID.');
            }
            $priority = $config['priority'] ?? 0;
            if (!is_int($priority) && !is_float($priority) && !is_string($priority)) {
                throw new InvalidConfigurationException("Middleware '{$service}' priority must be numeric.");
            }
            $tags = $config['tags'] ?? [];
            if (!is_array($tags)) {
                throw new InvalidConfigurationException("Middleware '{$service}' tags must be an array.");
            }
            $tags = array_values($tags);
            /** @var list<string> $tags Validated per element by the constructor below. */
            $tags = $tags;
            $group = $config['group'] ?? null;
            if ($group !== null && !is_string($group)) {
                throw new InvalidConfigurationException("Middleware '{$service}' group must be a string or null.");
            }
            try {
                return new self(
                    serviceId: $service,
                    priority: (int) $priority,
                    group: $group,
                    tags: $tags,
                );
            } catch (\InvalidArgumentException $e) {
                throw new InvalidConfigurationException($e->getMessage(), 0, $e);
            }
        }

        /**
         * @param string|array<string,mixed> $config
         */
        public static function fromLegacy(string|array $config): self
        {
            if (is_string($config)) {
                return new self($config);
            }
            return self::fromArray($config);
        }
    }
}
