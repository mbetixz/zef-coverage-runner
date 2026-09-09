<?php

declare(strict_types=1);

namespace Zef\Framework\Config {

    final class ModuleConfigProvider implements ConfigProviderInterface
    {
        public function __construct(private readonly ModuleInterface $module)
        {
        }
        #[\Override]
        public function getModuleName(): string
        {
            return $this->module->getName();
        }
        /** @return array<string,mixed> */
        #[\Override]
        public function getConfig(): array
        {
            return $this->module->getDefinition()->toArray();
        }
    }

}
