<?php

declare(strict_types=1);

namespace Zef\Framework\Exception {

    use Psr\Container\NotFoundExceptionInterface;

    final class ServiceNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
    {
        public function __construct(
            public readonly string $serviceId,
            public readonly ?string $module = null,
        ) {
            $suffix = $module !== null ? " (module: '{$module}')" : '';
            parent::__construct("Service '{$serviceId}' not found{$suffix}.");
        }
    }
}
