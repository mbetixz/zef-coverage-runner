<?php

declare(strict_types=1);

namespace Zef\Module\Core {
    use Zef\Framework\Config\ConfigProviderInterface;

    final class ConfigProvider implements ConfigProviderInterface
    {
        #[\Override]
        public function getModuleName(): string
        {
            return 'core';
        }

        #[\Override]
        public function getConfig(): array
        {
            return ['services' => ['core.handler.home' => ['factory' => static fn () => new HomeHandler(),'deps' => []],'core.handler.about' => ['factory' => static fn () => new AboutHandler(),'deps' => []]],'routes' => [['method' => 'GET','path' => '/','handler' => 'core.handler.home','priority' => 100],['method' => 'GET','path' => '/about','handler' => 'core.handler.about','priority' => 100]]];
        }
    }
}
