<?php

declare(strict_types=1);

namespace Zef\Framework\Message;

/**
 * Immutable context attached to a dispatched message.
 *
 * correlationId, when set, is 8..128 chars of [A-Za-z0-9._:-]. traceParent,
 * when set, is a W3C traceparent (2 hex version + 32 hex trace id + 16 hex
 * span id + 2 hex flags, with an optional <= 512 byte suffix). Attribute keys
 * are non-empty and <= 128 bytes; values are framework-opaque.
 *
 * @throws \InvalidArgumentException when correlationId, traceParent or an
 *         attribute key violates the bounds documented above.
 */
final readonly class MessageContext
{
    /** @param array<string,mixed> $attributes framework-opaque context attributes. */
    public function __construct(
        public ?string $correlationId = null,
        public ?string $traceParent = null,
        public array $attributes = [],
    ) {
        if ($correlationId !== null && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $correlationId) !== 1) {
            throw new \InvalidArgumentException('Invalid message correlation ID.');
        }
        if ($traceParent !== null && preg_match('/^[0-9a-f]{2}-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}(?:-[^\s]{1,512})?$/i', $traceParent) !== 1) {
            throw new \InvalidArgumentException('Invalid W3C traceparent.');
        }
        foreach ($attributes as $key => $_) {
            if ($key === '' || strlen($key) > 128) {
                throw new \InvalidArgumentException('Invalid message context attribute key.');
            }
        }
    }
}
