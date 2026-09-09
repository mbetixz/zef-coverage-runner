<?php

declare(strict_types=1);

namespace Zef\Framework\CQRS;

use Zef\Framework\Event\EventBusInterface;

final class CommandBus implements CommandBusInterface
{
    /** @var array<string,callable> */
    private array $handlers = [];
    /** @var list<CqrsMiddlewareInterface> */
    private array $middleware = [];
    private bool $frozen = false;

    public function __construct(
        private readonly ?IdempotencyStoreInterface $idempotencyStore = null,
        private readonly int $idempotencyTtlSeconds = 3600,
        private readonly ?EventBusInterface $eventBus = null,
    ) {
        if ($idempotencyTtlSeconds < 1) {
            throw new \InvalidArgumentException('CQRS idempotency TTL must be positive.');
        }
    }

    #[\Override]
    public function register(string $commandClass, callable|CommandHandlerInterface $handler): void
    {
        $this->assertMutable();
        $this->validateMessageClass($commandClass, 'command');
        if (isset($this->handlers[$commandClass])) {
            throw new CqrsHandlerConflictException("Command handler already registered for '{$commandClass}'.");
        }
        $this->handlers[$commandClass] = is_object($handler) && $handler instanceof CommandHandlerInterface ? $handler(...) : $handler;
    }

    #[\Override]
    public function use(CqrsMiddlewareInterface $middleware): void
    {
        $this->assertMutable();
        $this->middleware[] = $middleware;
    }

    #[\Override]
    public function dispatch(object $command, ?CqrsContext $context = null): mixed
    {
        $context ??= CqrsContext::create();
        $execute = function () use ($command, $context): mixed {
            $handler = $this->resolveHandler($command);
            $next = \Closure::fromCallable($handler);
            for ($i = count($this->middleware) - 1; $i >= 0; --$i) {
                $middleware = $this->middleware[$i];
                $next = static fn (object $message, CqrsContext $ctx): mixed => $middleware->process($message, $ctx, $next);
            }
            $result = $next($command, $context);
            if ($result instanceof CqrsEventResult) {
                foreach ($result->events as $event) {
                    $this->eventBus?->dispatchWithContext($event, $context->toEventContext());
                }
                return $result->result;
            }
            return $result;
        };
        if ($context->idempotencyKey !== null && $this->idempotencyStore !== null) {
            return $this->idempotencyStore->remember(hash('sha256', $command::class . '|' . $context->idempotencyKey), $execute, $this->idempotencyTtlSeconds);
        }
        return $execute();
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
            throw new CqrsHandlerNotFoundException('No command handler registered for ' . $class . '.');
        }
        if (count($matches) > 1) {
            throw new CqrsHandlerConflictException('Ambiguous command handlers for ' . $class . '. Register the concrete command class explicitly.');
        }
        return $matches[0][1];
    }

    private function validateMessageClass(string $class, string $type): void
    {
        if ($class === '' || (!class_exists($class) && !interface_exists($class))) {
            throw new \InvalidArgumentException("Invalid {$type} class '{$class}'.");
        }
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new \LogicException('Command bus is frozen.');
        }
    }
}
