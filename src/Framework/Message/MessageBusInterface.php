<?php

declare(strict_types=1);

namespace Zef\Framework\Message;

/**
 * Contract for application-level message dispatch. No network semantics are
 * implied.
 *
 * Implementations deliver the envelope to the handler registered for
 * $message->messageType. A null $context means the implementation may create a
 * default context. MessageResult::accepted is true once the bus accepted the
 * message for processing.
 */
interface MessageBusInterface
{
    /**
     * @throws \RuntimeException when no handler is registered for
     *         $message->messageType (implementation-dependent).
     */
    public function dispatch(MessageEnvelope $message, ?MessageContext $context = null): MessageResult;
}
