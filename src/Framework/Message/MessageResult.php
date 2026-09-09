<?php

declare(strict_types=1);

namespace Zef\Framework\Message;

/**
 * Immutable bus/transport dispatch result.
 *
 * messageId must match the dispatched envelope's id (8..128 chars of
 * [A-Za-z0-9._:-]); accepted reports whether the bus/transport accepted the
 * message; transportId is the broker-side identifier when one exists and is
 * at most 255 bytes when set.
 *
 * @throws \InvalidArgumentException when messageId is malformed or transportId
 *         exceeds 255 bytes.
 */
final readonly class MessageResult
{
    public function __construct(
        public string $messageId,
        public bool $accepted,
        public ?string $transportId = null,
    ) {
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $messageId) !== 1) {
            throw new \InvalidArgumentException('Invalid result message ID.');
        }
        if ($transportId !== null && strlen($transportId) > 255) {
            throw new \InvalidArgumentException('Transport ID exceeds the limit.');
        }
    }
}
