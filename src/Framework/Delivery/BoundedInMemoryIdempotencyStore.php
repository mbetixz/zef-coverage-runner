<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    use Zef\Framework\Transport\RemoteTransportResult;

    /**
     * Bounded in-memory idempotency store implementing both the store and the
     * guarantee contracts. Capacity-bounded (maxRecords 1..65_536) to refuse
     * unbounded growth. Keys and fingerprints validated 1-128 bytes; a
     * duplicate claim replays the stored record, a conflicting fingerprint
     * throws LogicException on complete()/completedResult(). Not durable and
     * single-process: do not use across replicas or restarts.
     */
    final class BoundedInMemoryIdempotencyStore implements IdempotencyStoreInterface, IdempotencyGuaranteeInterface
    {
        /** @var array<string, IdempotencyRecord> */
        private array $records = [];

        public function __construct(private readonly int $maxRecords = 256)
        {
            if ($maxRecords < 1 || $maxRecords > 65_536) {
                throw new \InvalidArgumentException('maxRecords must be between 1 and 65536.');
            }
        }

        /**
         * Supports any operation carrying both an idempotency key and a
         * fingerprint (the store keys records on the idempotency key).
         */
        #[\Override]
        public function supports(DeliveryOperation $operation): bool
        {
            return $operation->idempotencyKey !== null && $operation->operationFingerprint !== null;
        }

        /**
         * Claim a key: NEW when absent (records it), DUPLICATE for the same
         * fingerprint, CONFLICT for a different fingerprint. Throws
         * RuntimeException when the capacity bound is reached.
         */
        #[\Override]
        public function claim(string $idempotencyKey, string $operationFingerprint): IdempotencyClaim
        {
            self::validateKey($idempotencyKey);
            self::validateFingerprint($operationFingerprint);
            if (!isset($this->records[$idempotencyKey])) {
                if (count($this->records) >= $this->maxRecords) {
                    throw new \RuntimeException('Idempotency store capacity exhausted; refusing unbounded growth.');
                }
                $this->records[$idempotencyKey] = new IdempotencyRecord($idempotencyKey, $operationFingerprint, null);
                return IdempotencyClaim::NEW;
            }
            return $this->records[$idempotencyKey]->operationFingerprint === $operationFingerprint
                ? IdempotencyClaim::DUPLICATE
                : IdempotencyClaim::CONFLICT;
        }

        /**
         * Store the definitive result for a key. A conflicting fingerprint on
         * an existing key throws LogicException.
         */
        #[\Override]
        public function complete(string $idempotencyKey, string $operationFingerprint, RemoteTransportResult $result): void
        {
            self::validateKey($idempotencyKey);
            self::validateFingerprint($operationFingerprint);
            if (!isset($this->records[$idempotencyKey])) {
                if (count($this->records) >= $this->maxRecords) {
                    throw new \RuntimeException('Idempotency store capacity exhausted; refusing unbounded growth.');
                }
                $this->records[$idempotencyKey] = new IdempotencyRecord($idempotencyKey, $operationFingerprint, $result);
                return;
            }
            if ($this->records[$idempotencyKey]->operationFingerprint !== $operationFingerprint) {
                throw new \LogicException('Idempotency key conflict detected.');
            }
            $this->records[$idempotencyKey] = new IdempotencyRecord($idempotencyKey, $operationFingerprint, $result);
        }

        /**
         * Return the stored completed result for a key, or null while in
         * flight/absent. Conflicting fingerprint throws LogicException.
         */
        #[\Override]
        public function completedResult(string $idempotencyKey, string $operationFingerprint): ?RemoteTransportResult
        {
            self::validateKey($idempotencyKey);
            self::validateFingerprint($operationFingerprint);
            $record = $this->records[$idempotencyKey] ?? null;
            if ($record === null) {
                return null;
            }
            if ($record->operationFingerprint !== $operationFingerprint) {
                throw new \LogicException('Idempotency key conflict detected.');
            }
            return $record->result;
        }

        private static function validateKey(string $value): void
        {
            if ($value === '' || strlen($value) > 128) {
                throw new \InvalidArgumentException('Idempotency key must be 1-128 bytes.');
            }
        }

        private static function validateFingerprint(string $value): void
        {
            if ($value === '' || strlen($value) > 128) {
                throw new \InvalidArgumentException('Operation fingerprint must be 1-128 bytes.');
            }
        }
    }
}
