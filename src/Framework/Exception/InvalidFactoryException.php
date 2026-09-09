<?php

declare(strict_types=1);

namespace Zef\Framework\Exception {

    use Psr\Container\ContainerExceptionInterface;

    final class InvalidFactoryException extends \RuntimeException implements ContainerExceptionInterface
    {
    }
}
