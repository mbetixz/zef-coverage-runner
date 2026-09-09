<?php

declare(strict_types=1);

namespace Zef\Framework\Validation {

    use Zef\Framework\Exception\InvalidHeaderException;

    final class HeaderValidator
    {
        public function assertName(string $name): void
        {
            if ($name === '' || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
                throw new InvalidHeaderException("Invalid header name '{$name}'.");
            }
        }

        public function assertValue(string $name, string $value): void
        {
            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new InvalidHeaderException("Invalid header value for '{$name}'.");
            }
        }
    }
}
