<?php

declare(strict_types=1);

namespace Zef\Framework\Cache {
    interface CacheKeyNormalizerInterface
    {
        public function normalize(string $key): string;
    }
}
