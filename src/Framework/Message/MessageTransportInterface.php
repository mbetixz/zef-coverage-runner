<?php

declare(strict_types=1);

namespace Zef\Framework\Message;

/**
 * Port for broker/queue adapters; the core does not provide a network
 * transport.
 *
 * send() transmits the envelope outbound and returns the transport outcome.
 * MessageResult::accepted is true when the broker/queue acknowledged receipt;
 * transportId carries the broker-side identifier when one is available. No
 * end-to-end delivery guarantee is implied.
 */
interface MessageTransportInterface
{
    public function send(MessageEnvelope $message, MessageContext $context): MessageResult;
}
