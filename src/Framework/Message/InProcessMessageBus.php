<?php

declare(strict_types=1);

namespace Zef\Framework\Message;

/**
 * In-process message bus: dispatches envelopes synchronously to the handler
 * registered for the envelope's messageType, through an optional middleware
 * chain (at most 32). registerHandler() rejects duplicate messageTypes and
 * malformed type names; dispatch() throws when no handler is registered.
 * MessageResult::accepted is always true on a successful dispatch.
 */
final class InProcessMessageBus implements MessageBusInterface
{
    /** @var array<string,MessageHandlerInterface> */
    private array $handlers = [];
    /** @var list<MessageMiddlewareInterface> */
    private array $middleware = [];

    /** @param array<string,MessageHandlerInterface> $handlers
     * @param array<int,MessageMiddlewareInterface> $middleware
     */
    public function __construct(array $handlers = [], array $middleware = [])
    {
        foreach ($handlers as $type => $handler) {
            $this->registerHandler($type, $handler);
        }
        foreach ($middleware as $item) {
            $this->addMiddleware($item);
        }
    }

    /**
     * @throws \InvalidArgumentException when $messageType is malformed.
     * @throws \LogicException when a handler is already registered for $messageType.
     */
    public function registerHandler(string $messageType, MessageHandlerInterface $handler): void
    {
        if (preg_match('/^[A-Za-z0-9._:\/-]{1,255}$/', $messageType) !== 1) {
            throw new \InvalidArgumentException('Invalid message type.');
        }
        if (isset($this->handlers[$messageType])) {
            throw new \LogicException('Message handler already registered.');
        }
        $this->handlers[$messageType] = $handler;
    }

    /**
     * @throws \LogicException when the middleware limit (32) is reached.
     */
    public function addMiddleware(MessageMiddlewareInterface $middleware): void
    {
        if (count($this->middleware) >= 32) {
            throw new \LogicException('Message middleware limit exceeded.');
        }
        $this->middleware[] = $middleware;
    }

    #[\Override]
    public function dispatch(MessageEnvelope $message, ?MessageContext $context = null): MessageResult
    {
        $context ??= new MessageContext();
        $handler = $this->handlers[$message->messageType] ?? null;
        if ($handler === null) {
            throw new \RuntimeException('No message handler registered.');
        }
        $index = 0;
        $next = function (MessageEnvelope $current, MessageContext $ctx) use (&$index, &$next): MessageResult {
            if ($index < count($this->middleware)) {
                $middleware = $this->middleware[$index++];
                return $middleware->process($current, $ctx, function (MessageEnvelope $m, MessageContext $c) use (&$next): MessageResult {
                    return $next($m, $c);
                });
            }
            ($this->handlers[$current->messageType])($current, $ctx);
            return new MessageResult($current->messageId, true);
        };
        return $next($message, $context);
    }
}
