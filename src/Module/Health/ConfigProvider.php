<?php

declare(strict_types=1);

namespace Zef\Module\Health {
    use Zef\Framework\Config\ConfigProviderInterface;

    final class ConfigProvider implements ConfigProviderInterface
    {
        #[\Override]
        public function getModuleName(): string
        {
            return 'health';
        }

        /** @return array{services: array<string, array{factory: callable, deps: list<string>}>, routes: list<array{method: string, path: string, handler: string, priority: int}>} */
        #[\Override]
        public function getConfig(): array
        {
            return [
                'services' => [
                    'health.handler.live' => ['factory' => static fn (): LiveHandler => new LiveHandler(), 'deps' => []],
                    'health.handler.ready' => ['factory' => static fn (): ReadyHandler => new ReadyHandler(), 'deps' => []],
                ],
                'routes' => [
                    ['method' => 'GET', 'path' => '/health/live', 'handler' => 'health.handler.live', 'priority' => 2000],
                    ['method' => 'GET', 'path' => '/health/ready', 'handler' => 'health.handler.ready', 'priority' => 2000],
                ],
            ];
        }
    }
}
