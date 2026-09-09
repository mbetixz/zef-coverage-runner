<?php

declare(strict_types=1);

namespace Zef\Framework\Config {

    abstract class AbstractModule implements ModuleInterface
    {
        public function __construct(private readonly ModuleDefinition $definition)
        {
        }
        #[\Override]
        public function getName(): string
        {
            return $this->definition->name;
        }
        #[\Override]
        public function getDefinition(): ModuleDefinition
        {
            return $this->definition;
        }
        #[\Override]
        public function register(ModuleContext $context): void
        {
        }
        #[\Override]
        public function boot(ModuleContext $context): void
        {
        }
        #[\Override]
        public function start(ModuleContext $context): void
        {
        }
        #[\Override]
        public function shutdown(ModuleContext $context): void
        {
        }
    }

}
