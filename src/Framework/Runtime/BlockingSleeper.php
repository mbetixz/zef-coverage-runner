<?php

declare(strict_types=1);

namespace Zef\Framework\Runtime;

/**
 * Explicit infrastructure boundary for bounded blocking delays.
 *
 * Core components must call this boundary instead of invoking usleep()
 * directly. The implementation is intentionally tiny so it can later be
 * replaced by an event-loop-aware adapter without changing callers.
 *
 * @internal
 */
final class BlockingSleeper
{
    private function __construct()
    {
    }

    public static function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException('Sleep duration cannot be negative.');
        }

        if ($milliseconds === 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }
}
