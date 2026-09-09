<?php

declare(strict_types=1);

namespace Zef\Framework\Cache {
    interface CacheSerializerInterface
    {
        public function serialize(mixed $value): string;
        public function deserialize(string $payload): mixed;
    }
}
