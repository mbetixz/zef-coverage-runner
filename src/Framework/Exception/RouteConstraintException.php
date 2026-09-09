<?php

declare(strict_types=1);

namespace Zef\Framework\Exception {
    final class RouteConstraintException extends \RuntimeException
    {
        public function __construct(
            public readonly string $param,
            public readonly string $type,
            public readonly string $value,
        ) {
            parent::__construct("Route param '{$param}' failed constraint '{$type}'.");
        }
    }
}
