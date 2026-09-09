<?php

declare(strict_types=1);

namespace Zef\Framework\Message;

/**
 * Immutable transport-neutral message envelope.
 *
 * The payload is deliberately opaque to the framework. Transport adapters may
 * serialize it later without forcing a broker, wire format, or network client
 * into the framework core.
 *
 * Identifier bounds: messageId is 8..128 chars of [A-Za-z0-9._:-]; messageType
 * is 1..255 chars of [A-Za-z0-9._:\/-]. Headers are limited to 64 entries with
 * names of 1..128 chars of [A-Za-z0-9._-] and values <= 4096 bytes; all bounds
 * are enforced in the constructor.
 */
final readonly class MessageEnvelope
{
    /**
     * @param array<string,string> $headers header map (at most 64 entries).
     *
     * @throws \InvalidArgumentException when an identifier, a header name or
     *         value, or the header count violates the bounds documented above.
     */
    public function __construct(
        public string $messageId,
        public string $messageType,
        public mixed $payload,
        public array $headers = [],
    ) {
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $messageId) !== 1) {
            throw new \InvalidArgumentException('Invalid message ID.');
        }
        if (preg_match('/^[A-Za-z0-9._:\/-]{1,255}$/', $messageType) !== 1) {
            throw new \InvalidArgumentException('Invalid message type.');
        }
        if (count($headers) > 64) {
            throw new \InvalidArgumentException('Message header count exceeds the limit.');
        }
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name) !== 1) {
                throw new \InvalidArgumentException('Invalid message header name.');
            }
            if (strlen($value) > 4096) {
                throw new \InvalidArgumentException('Message header value exceeds the limit.');
            }
        }
    }
}
