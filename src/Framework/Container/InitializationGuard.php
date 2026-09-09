<?php

declare(strict_types=1);

namespace Zef\Framework\Container {
    interface InitializationGuard
    {
        /**
         * Execute a factory once for a synchronization key.
         *
         * Implementations must preserve the factory result and propagate its
         * exception unchanged unless their documented guard policy says otherwise.
         */
        public function synchronized(string $key, \Closure $factory): mixed;
    }
}
