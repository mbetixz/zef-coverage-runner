<?php

declare(strict_types=1);

namespace Zef\Framework\CQRS;

interface CommandBusInterface
{
    public function register(string $commandClass, callable|CommandHandlerInterface $handler): void;
    public function use(CqrsMiddlewareInterface $middleware): void;
    public function dispatch(object $command, ?CqrsContext $context = null): mixed;
    public function freeze(): void;
    public function isFrozen(): bool;
}
