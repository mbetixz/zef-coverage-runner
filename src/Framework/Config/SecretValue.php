<?php

declare(strict_types=1);

namespace Zef\Framework\Config {

    final readonly class SecretValue
    {
        public function __construct(private string $value)
        {
            if ($value === '' || strlen($value) > 4096) {
                throw new \InvalidArgumentException('Secret value is empty or exceeds the 4096-byte limit.');
            }
        }

        public function reveal(): string
        {
            return $this->value;
        }
        #[\Override]
        public function __toString(): string
        {
            return '[REDACTED]';
        }
    }

}
