<?php

declare(strict_types=1);

namespace Zef\Framework\Cache {
    final class DefaultCacheKeyNormalizer implements CacheKeyNormalizerInterface
    {
        #[\Override]
        public function normalize(string $key): string
        {
            $key = trim($key);
            if ($key === '' || strlen($key) > 250 || preg_match('/^[A-Za-z0-9._:\/-]+$/', $key) !== 1) {
                throw new \InvalidArgumentException('Invalid cache key.');
            }
            return $key;
        }
    }
}
