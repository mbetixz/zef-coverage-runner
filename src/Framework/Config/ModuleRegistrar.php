<?php

declare(strict_types=1);

namespace Zef\Framework\Config {

    interface ModuleRegistrar
    {
        /** @param array<string,mixed>|ModuleDefinition $config */
        public function registerModule(string $module, ModuleDefinition|array $config): void;
    }

}
