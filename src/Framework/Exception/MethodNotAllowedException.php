<?php

declare(strict_types=1);

namespace Zef\Framework\Exception {
    final class MethodNotAllowedException extends \RuntimeException
    {
        /** @param list<string> $allowedMethods */
        public function __construct(public readonly string $method, public readonly string $path, public readonly array $allowedMethods)
        {
            parent::__construct("Method '{$method}' is not allowed for {$path}.");
        }
    }
}
