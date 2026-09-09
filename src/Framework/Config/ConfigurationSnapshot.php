<?php

declare(strict_types=1);

namespace Zef\Framework\Config {

    final readonly class ConfigurationSnapshot
    {
        /** @param array<string,mixed> $values */
        public function __construct(public array $values, public int $version = 1)
        {
            if ($version < 1) {
                throw new \InvalidArgumentException('Configuration version must be positive.');
            }
        }
    }

}
