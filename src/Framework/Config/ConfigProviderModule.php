<?php

declare(strict_types=1);

namespace Zef\Framework\Config {

    final class ConfigProviderModule extends AbstractModule
    {
        public function __construct(private readonly ConfigProviderInterface $provider)
        {
            parent::__construct(ModuleDefinition::fromArray($provider->getModuleName(), $provider->getConfig()));
        }
        public function provider(): ConfigProviderInterface
        {
            return $this->provider;
        }
    }

}
