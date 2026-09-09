<?php

declare(strict_types=1);

namespace Zef\Framework\Container {

    /**
     * Closed set of supported service lifecycle policies.
     *
     * @internal The string constants are retained for configuration and
     * registry compatibility.
     */
    final class ServiceLifetime
    {
        public const string SINGLETON = 'singleton';
        public const string REQUEST = 'request';
        public const string TRANSIENT = 'transient';

        /** @throws \InvalidArgumentException when the lifetime is unsupported. */
        public static function assert(string $lifetime): void
        {
            if (!in_array($lifetime, [self::SINGLETON, self::REQUEST, self::TRANSIENT], true)) {
                throw new \InvalidArgumentException("Unknown service lifetime '{$lifetime}'.");
            }
        }
    }
}
