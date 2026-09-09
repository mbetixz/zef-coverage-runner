<?php

declare(strict_types=1);

namespace Zef\Framework\Router {

    final readonly class RouteDefinition
    {
        public readonly string $method;
        public readonly string $path;
        public readonly string $handler;

        public function __construct(
            string $method,
            string $path,
            string $handler,
            public readonly int $priority = 0,
        ) {
            $method = strtoupper(trim($method));
            if ($method === '' || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $method) !== 1) {
                throw new \InvalidArgumentException("Invalid HTTP method '{$method}'.");
            }
            if ($path === '' || $path[0] !== '/') {
                throw new \InvalidArgumentException("Route path '{$path}' must begin with '/'.");
            }
            if ($handler === '') {
                throw new \InvalidArgumentException('Route handler service ID must not be empty.');
            }
            $this->method = $method;
            $this->path = $path;
            $this->handler = $handler;
        }

        /** @param array<string,mixed> $config */
        public static function fromArray(array $config): self
        {
            $method = $config['method'] ?? 'GET';
            $path = $config['path'] ?? '/';
            $handler = $config['handler'] ?? '';
            $priority = $config['priority'] ?? 0;
            if (!is_string($method) || !is_string($path) || !is_string($handler)) {
                throw new \InvalidArgumentException('Route definition method, path, and handler must be strings.');
            }
            if ((!is_int($priority) && !is_float($priority) && !is_string($priority)) || (is_string($priority) && !is_numeric($priority))) {
                throw new \InvalidArgumentException('Route definition priority must be numeric.');
            }
            return new self(
                method: strtoupper(trim($method)),
                path: $path,
                handler: $handler,
                priority: (int) $priority,
            );
        }
    }
}
