<?php

declare(strict_types=1);

namespace Zef\Framework\Container {
    final class FailFastInitializationGuard implements InitializationGuard
    {
        /** @var array<string, bool> */
        private array $active = [];

        #[\Override]
        public function synchronized(string $key, \Closure $factory): mixed
        {
            if (isset($this->active[$key])) {
                throw new \Zef\Framework\Exception\ConcurrentServiceInitializationException($key);
            }

            $this->active[$key] = true;
            try {
                return $factory();
            } finally {
                unset($this->active[$key]);
            }
        }
    }
}
