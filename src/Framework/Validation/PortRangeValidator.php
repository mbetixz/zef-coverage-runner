<?php

declare(strict_types=1);

namespace Zef\Framework\Validation {

    final class PortRangeValidator
    {
        public function assert(?int $port): void
        {
            if ($port !== null && ($port < 1 || $port > 65535)) {
                throw new \InvalidArgumentException("Invalid port: {$port}.");
            }
        }
    }
}
