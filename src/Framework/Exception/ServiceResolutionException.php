<?php

declare(strict_types=1);

namespace Zef\Framework\Exception {

    use Psr\Container\ContainerExceptionInterface;

    final class ServiceResolutionException extends \RuntimeException implements ContainerExceptionInterface
    {
        public function __construct(string $id, string $reason, ?\Throwable $previous = null)
        {
            parent::__construct("Cannot resolve service '{$id}': {$reason}", 0, $previous);
        }
    }
}
