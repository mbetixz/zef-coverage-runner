<?php

declare(strict_types=1);

namespace Zef\Framework\Cache {
    interface CacheClockInterface
    {
        public function nowUnixNano(): int;
    }
}
