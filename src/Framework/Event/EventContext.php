<?php

declare(strict_types=1);

namespace Zef\Framework\Event;

final readonly class EventContext
{
    /** @param array<string,mixed> $attributes */
    public function __construct(
        public string $eventId,
        public int $occurredAtUnixNano,
        public ?string $correlationId = null,
        public ?string $traceParent = null,
        public array $attributes = [],
    ) {
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $eventId) !== 1) {
            throw new \InvalidArgumentException('Invalid event ID.');
        }
        if ($correlationId !== null && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $correlationId) !== 1) {
            throw new \InvalidArgumentException('Invalid correlation ID.');
        }
        if ($traceParent !== null && preg_match('/^[0-9a-f]{2}-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}(?:-[^\s]{1,512})?$/i', $traceParent) !== 1) {
            throw new \InvalidArgumentException('Invalid W3C traceparent.');
        }
    }
}
