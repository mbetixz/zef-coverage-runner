<?php

declare(strict_types=1);

namespace Zef\Framework\Container {

    final class ServiceRegistryView
    {
        public function __construct(private readonly ServiceRegistry $registry)
        {
        }
        /** @return array<string,ServiceDefinition> */
        public function definitions(): array
        {
            return $this->registry->definitions();
        }
        /** @return array<string,mixed> */
        public function factories(): array
        {
            return $this->registry->factories();
        }
        /** @return array<string,string> */
        public function aliases(): array
        {
            return $this->registry->aliases();
        }
        /** @return array<string,list<string>> */
        public function depsOf(): array
        {
            return $this->registry->depsOf();
        }
        /** @return array<string,string|null> */
        public function moduleOf(): array
        {
            return $this->registry->moduleOf();
        }
        /** @return array<string,string> */
        public function lifetimeOf(): array
        {
            return $this->registry->lifetimeOf();
        }
    }
}
