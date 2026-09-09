<?php

declare(strict_types=1);

namespace Zef\Framework\Validation {

    final class HttpMethodValidator
    {
        public static function assert(string $method): void
        {
            if ($method === '' || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $method) !== 1) {
                throw new \InvalidArgumentException("Invalid HTTP method '{$method}'.");
            }
        }
    }
}
