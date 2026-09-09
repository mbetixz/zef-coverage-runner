<?php

declare(strict_types=1);

namespace Zef\Framework\Exception {

    use Psr\Container\ContainerExceptionInterface;

    final class ConcurrentServiceInitializationException extends \RuntimeException implements ContainerExceptionInterface
    {
        public function __construct(public readonly string $serviceId)
        {
            parent::__construct("Concurrent initialization detected for service '{$serviceId}'.");
        }
    }
}
