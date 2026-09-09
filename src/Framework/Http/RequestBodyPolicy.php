<?php

declare(strict_types=1);

namespace Zef\Framework\Http {
    final readonly class RequestBodyPolicy
    {
        public const int DEFAULT_MAX_BYTES = 2097152;
        public function __construct(public int $maxBytes = self::DEFAULT_MAX_BYTES)
        {
            if ($maxBytes < 1) {
                throw new \InvalidArgumentException('Request body maxBytes must be greater than zero.');
            }
        }
    }
}
