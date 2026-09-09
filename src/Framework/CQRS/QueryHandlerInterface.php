<?php

declare(strict_types=1);

namespace Zef\Framework\CQRS;

interface QueryHandlerInterface
{
    public function __invoke(object $query, CqrsContext $context): mixed;
}
