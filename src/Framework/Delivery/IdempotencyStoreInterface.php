<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    use Zef\Framework\Transport\RemoteTransportResult;

    /**
     * Contract of an idempotency store.
     *
     * claim() records a key+fingerprint and returns whether it is NEW,
     * DUPLICATE (same fingerprint, replay completedResult) or CONFLICT.
     * complete() stores the definitive result; completedResult() returns the
     * stored result or null while in flight. Implementations must validate key
     * and fingerprint (1-128 bytes) and reject re-claim conflicts.
     */
    interface IdempotencyStoreInterface
    {
        public function claim(string $idempotencyKey, string $operationFingerprint): IdempotencyClaim;

        public function complete(string $idempotencyKey, string $operationFingerprint, RemoteTransportResult $result): void;

        public function completedResult(string $idempotencyKey, string $operationFingerprint): ?RemoteTransportResult;
    }
}
