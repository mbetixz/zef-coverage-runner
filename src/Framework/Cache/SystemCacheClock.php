<?php

declare(strict_types=1);

namespace Zef\Framework\Cache {
    final class SystemCacheClock implements CacheClockInterface
    {
        #[\Override]
        public function nowUnixNano(): int
        {
            return hrtime(true);
        }
    }
}
