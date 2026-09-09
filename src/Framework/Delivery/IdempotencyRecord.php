<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    use Zef\Framework\Transport\RemoteTransportResult;

    /**
     * Immutable persisted record for one idempotency key.
     *
     * result is null while the operation is in flight and set once completed;
     * a completed record lets a duplicate claim replay the exact result.
     */
    final readonly class IdempotencyRecord
    {
        public function __construct(
            public string $idempotencyKey,
            public string $operationFingerprint,
            public ?RemoteTransportResult $result,
        ) {
        }
    }
}
