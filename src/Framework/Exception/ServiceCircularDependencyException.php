<?php

declare(strict_types=1);

namespace Zef\Framework\Exception {

    use Psr\Container\ContainerExceptionInterface;

    final class ServiceCircularDependencyException extends \RuntimeException implements ContainerExceptionInterface
    {
        /** @param list<string> $chain */
        public function __construct(public readonly array $chain)
        {
            parent::__construct('Circular service dependency detected: ' . implode(' -> ', $chain));
        }

        /** @return list<string> */
        public function getChain(): array
        {
            return $this->chain;
        }
    }
}
