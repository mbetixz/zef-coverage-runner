<?php

declare(strict_types=1);

namespace Zef\Framework\Runtime {
    interface RuntimeExtensionInterface
    {
        public function name(): string;

        public function start(RuntimeExtensionContext $context): void;

        public function ready(RuntimeExtensionContext $context): void;

        public function draining(RuntimeExtensionContext $context): void;

        public function stop(RuntimeExtensionContext $context): void;
    }

}
