<?php

declare(strict_types=1);

namespace Zef\Framework\Cache {
    final readonly class CacheItem
    {
        public function __construct(
            public mixed $value,
            public ?int $expiresAtUnixNano = null,
        ) {
        }

        public function isExpired(?int $nowUnixNano = null): bool
        {
            return $this->expiresAtUnixNano !== null
                && ($nowUnixNano ?? hrtime(true)) >= $this->expiresAtUnixNano;
        }
    }
}
