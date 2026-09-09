<?php

declare(strict_types=1);

namespace Zef\Framework\Message;

/**
 * Contract for cross-cutting message pipeline behavior.
 *
 * process() may run logic before and/or after invoking $next($message,
 * $context), which continues the pipeline and eventually reaches the handler.
 * A middleware may short-circuit by returning a MessageResult without calling
 * $next; it must always return a MessageResult.
 */
interface MessageMiddlewareInterface
{
    public function process(MessageEnvelope $message, MessageContext $context, \Closure $next): MessageResult;
}
