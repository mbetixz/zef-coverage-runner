<?php

declare(strict_types=1);

namespace Zef\Framework\Config {

    interface ModuleInterface
    {
        public function getName(): string;
        public function getDefinition(): ModuleDefinition;
        public function register(ModuleContext $context): void;
        public function boot(ModuleContext $context): void;
        public function start(ModuleContext $context): void;
        public function shutdown(ModuleContext $context): void;
    }

}
