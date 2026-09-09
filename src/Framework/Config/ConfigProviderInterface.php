<?php

declare(strict_types=1);

namespace Zef\Framework\Config {

    interface ConfigProviderInterface
    {
        public function getModuleName(): string;
        /** @return array<string,mixed> */
        public function getConfig(): array;
    }

}
