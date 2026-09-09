<?php

declare(strict_types=1);

namespace Zef\Framework\Exception {
    final class RouteNotFoundException extends \RuntimeException
    {
        public function __construct(public readonly string $method, public readonly string $path)
        {
            parent::__construct("No route matched [{$method}] {$path}.");
        }
    }
}
