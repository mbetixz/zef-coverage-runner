<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    final readonly class LogRecord
    {
        /** @param array<string,mixed> $attributes */
        public function __construct(public string $severity, public string $body, public int $timeUnixNano, public array $attributes = [])
        {
        }
    }
}
