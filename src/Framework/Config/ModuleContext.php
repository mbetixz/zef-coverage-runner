<?php

declare(strict_types=1);

namespace Zef\Framework\Config {

    final class ModuleContext
    {
        public function __construct(
            private readonly ModuleDefinition $definition,
            private readonly \Psr\Container\ContainerInterface $container,
        ) {
        }

        public function name(): string
        {
            return $this->definition->name;
        }
        public function definition(): ModuleDefinition
        {
            return $this->definition;
        }
        public function container(): \Psr\Container\ContainerInterface
        {
            return $this->container;
        }
    }

}
