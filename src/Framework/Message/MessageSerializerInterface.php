<?php

declare(strict_types=1);

namespace Zef\Framework\Message;

/**
 * Contract for converting an envelope to/from an external wire representation.
 *
 * serialize() must be the inverse of deserialize(): round-tripping an envelope
 * through both yields an equivalent envelope. Wire-format and size-limit
 * details are implementation-specific; malformed input causes an exception.
 */
interface MessageSerializerInterface
{
    /** @throws \InvalidArgumentException when the payload is not serializable. */
    public function serialize(MessageEnvelope $message): string;

    /** @throws \InvalidArgumentException when the wire payload is malformed or violates limits. */
    public function deserialize(string $payload): MessageEnvelope;
}
