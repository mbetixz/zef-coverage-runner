<?php

declare(strict_types=1);

namespace Zef\Framework\CQRS;

final class QueryBus implements QueryBusInterface
{
    /** @var array<string,callable> */
    private array $handlers = [];
    /** @var list<CqrsMiddlewareInterface> */
    private array $middleware = [];
    private bool $frozen = false;

    #[\Override]
    public function register(string $queryClass, callable|QueryHandlerInterface $handler): void
    {
        if ($this->frozen) {
            throw new \LogicException('Query bus is frozen.');
        }
        if ($queryClass === '' || (!class_exists($queryClass) && !interface_exists($queryClass))) {
            throw new \InvalidArgumentException("Invalid query class '{$queryClass}'.");
        }
        if (isset($this->handlers[$queryClass])) {
            throw new CqrsHandlerConflictException("Query handler already registered for '{$queryClass}'.");
        }
        $this->handlers[$queryClass] = is_object($handler) && $handler instanceof QueryHandlerInterface ? $handler(...) : $handler;
    }

    #[\Override]
    public function use(CqrsMiddlewareInterface $middleware): void
    {
        if ($this->frozen) {
            throw new \LogicException('Query bus is frozen.');
        }
        $this->middleware[] = $middleware;
    }

    #[\Override]
    public function ask(object $query, ?CqrsContext $context = null): mixed
    {
        $context ??= CqrsContext::create();
        $handler = $this->resolveHandler($query);
        $next = \Closure::fromCallable($handler);
        for ($i = count($this->middleware) - 1; $i >= 0; --$i) {
            $middleware = $this->middleware[$i];
            $next = static fn (object $message, CqrsContext $ctx): mixed => $middleware->process($message, $ctx, $next);
        }
        return $next($query, $context);
    }

    #[\Override] public function freeze(): void
    {
        $this->frozen = true;
    }
    #[\Override] public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /** @return callable */
    private function resolveHandler(object $message): callable
    {
        $class = $message::class;
        if (isset($this->handlers[$class])) {
            return $this->handlers[$class];
        }
        $matches = [];
        foreach ($this->handlers as $registered => $handler) {
            if (is_a($message, $registered)) {
                $matches[] = [$registered, $handler];
            }
        }
        if ($matches === []) {
            throw new CqrsHandlerNotFoundException('No query handler registered for ' . $class . '.');
        }
        if (count($matches) > 1) {
            throw new CqrsHandlerConflictException('Ambiguous query handlers for ' . $class . '. Register the concrete query class explicitly.');
        }
        return $matches[0][1];
    }
}
