<?php

declare(strict_types=1);

namespace Zef\Framework\CQRS;

interface QueryBusInterface
{
    public function register(string $queryClass, callable|QueryHandlerInterface $handler): void;
    public function use(CqrsMiddlewareInterface $middleware): void;
    public function ask(object $query, ?CqrsContext $context = null): mixed;
    public function freeze(): void;
    public function isFrozen(): bool;
}
