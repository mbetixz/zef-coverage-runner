<?php

declare(strict_types=1);

namespace Zef\Framework\CQRS;

interface CqrsMiddlewareInterface
{
    public function process(object $message, CqrsContext $context, \Closure $next): mixed;
}
