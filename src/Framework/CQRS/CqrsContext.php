<?php

declare(strict_types=1);

namespace Zef\Framework\CQRS;

use Zef\Framework\Event\EventContext;

final readonly class CqrsContext
{
    /** @param array<string,mixed> $attributes */
    public function __construct(
        public string $correlationId,
        public ?string $traceParent = null,
        public ?string $idempotencyKey = null,
        public array $attributes = [],
    ) {
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $correlationId) !== 1) {
            throw new \InvalidArgumentException('Invalid CQRS correlation ID.');
        }
        if ($traceParent !== null && preg_match('/^[0-9a-f]{2}-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}(?:-[^\s]{1,512})?$/i', $traceParent) !== 1) {
            throw new \InvalidArgumentException('Invalid CQRS traceparent.');
        }
        if ($idempotencyKey !== null && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('Invalid CQRS idempotency key.');
        }
        foreach ($attributes as $key => $_) {
            if ($key === '' || strlen($key) > 128) {
                throw new \InvalidArgumentException('Invalid CQRS context attribute key.');
            }
        }
    }

    public function toEventContext(): EventContext
    {
        return new EventContext(
            eventId: bin2hex(random_bytes(16)),
            occurredAtUnixNano: (int) (microtime(true) * 1_000_000_000),
            correlationId: $this->correlationId,
            traceParent: $this->traceParent,
            attributes: $this->attributes,
        );
    }

    /** @param array<string,mixed> $attributes */
    public static function create(?string $traceParent = null, ?string $idempotencyKey = null, array $attributes = []): self
    {
        return new self(bin2hex(random_bytes(16)), $traceParent, $idempotencyKey, $attributes);
    }
}
