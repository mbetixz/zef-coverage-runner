<?php

declare(strict_types=1);

namespace Zef\Framework\CQRS;

interface CommandHandlerInterface
{
    public function __invoke(object $command, CqrsContext $context): mixed;
}
