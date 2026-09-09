<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    final class TelemetryClock
    {
        public static function nowNs(): int
        {
            return hrtime(true);
        }
        public static function nowUnixNano(): int
        {
            return (int) (microtime(true) * 1_000_000_000);
        }
    }
}
