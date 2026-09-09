<?php

declare(strict_types=1);

namespace Zef\Framework\Message;

/**
 * Contract implemented by message consumers.
 *
 * A handler is registered for one messageType and receives every envelope of
 * that type. The return value is deliberately unconstrained (mixed): handlers
 * signal their outcome through the envelope payload or their own mechanism,
 * and bus implementations decide whether to consume the return value.
 */
interface MessageHandlerInterface
{
    public function __invoke(MessageEnvelope $message, MessageContext $context): mixed;
}
