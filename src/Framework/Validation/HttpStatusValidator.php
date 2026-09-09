<?php

declare(strict_types=1);

namespace Zef\Framework\Validation {

    final class HttpStatusValidator
    {
        public function assert(int $code): void
        {
            if ($code < 100 || $code > 599) {
                throw new \InvalidArgumentException("Invalid HTTP status code: {$code}.");
            }
        }
    }
}
