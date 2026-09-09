<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    /**
     * Immutable description of the operation being delivered.
     *
     * Invariants: operationId/operation are non-empty tokens <= 128 bytes;
     * optional tokens (idempotencyKey, operationFingerprint) share the same
     * bound; DeliveryMode::IDEMPOTENT requires both key and fingerprint;
     * a key without a fingerprint is rejected (a key can only be meaningful
     * with a fingerprint of the exact request).
     */
    final readonly class DeliveryOperation
    {
        public function __construct(
            public string $operationId,
            public string $operation,
            public DeliverySafety $safety,
            public DeliveryMode $mode,
            public ?string $idempotencyKey = null,
            public ?string $operationFingerprint = null,
        ) {
            self::validateToken($operationId, 'operationId');
            self::validateToken($operation, 'operation');
            self::validateOptionalToken($idempotencyKey, 'idempotencyKey');
            self::validateOptionalToken($operationFingerprint, 'operationFingerprint');

            if ($mode === DeliveryMode::IDEMPOTENT && ($idempotencyKey === null || $operationFingerprint === null)) {
                throw new \InvalidArgumentException('Idempotent delivery requires idempotencyKey and operationFingerprint.');
            }
            if ($idempotencyKey !== null && $operationFingerprint === null) {
                throw new \InvalidArgumentException('An idempotencyKey requires an operationFingerprint.');
            }
        }

        private static function validateToken(string $value, string $name): void
        {
            if ($value === '' || strlen($value) > 128) {
                throw new \InvalidArgumentException(sprintf('%s must be 1-128 bytes.', $name));
            }
        }

        private static function validateOptionalToken(?string $value, string $name): void
        {
            if ($value !== null) {
                self::validateToken($value, $name);
            }
        }
    }
}
