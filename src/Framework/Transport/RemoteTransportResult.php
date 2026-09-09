<?php

declare(strict_types=1);

namespace Zef\Framework\Transport {
    /**
     * Immutable outcome of a remote transport send attempt.
     *
     * outcome classifies the result (see TransportOutcome); payload, when
     * non-null, is the response body and is at most 1 MiB. metadata follows the
     * same bounds as RemoteRequest::metadata (at most 32 entries, string keys
     * <= 128 bytes, scalar-or-null values). INDETERMINATE is the only outcome
     * for which isDefinitive() returns false: the operation may or may not
     * have executed remotely.
     */
    final readonly class RemoteTransportResult
    {
        /**
         * @param array<int|string, mixed> $metadata
         *
         * @throws \InvalidArgumentException when the payload exceeds 1 MiB or a
         *         metadata entry violates the documented bounds.
         */
        public function __construct(
            public TransportOutcome $outcome,
            public ?string $payload = null,
            public array $metadata = [],
        ) {
            if ($payload !== null && strlen($payload) > 1_048_576) {
                throw new \InvalidArgumentException('Remote result payload exceeds the 1 MiB contract limit.');
            }
            if (count($metadata) > 32) {
                throw new \InvalidArgumentException('Remote result metadata may contain at most 32 entries.');
            }
            foreach ($metadata as $key => $value) {
                if (!is_string($key) || trim($key) === '' || strlen($key) > 128) {
                    throw new \InvalidArgumentException('Remote result metadata keys must be non-empty strings <= 128 bytes.');
                }
                if ($value !== null && !is_scalar($value)) {
                    throw new \InvalidArgumentException('Remote result metadata values must be scalar or null.');
                }
                if (is_string($value) && strlen($value) > 1024) {
                    throw new \InvalidArgumentException('Remote result metadata string values may not exceed 1024 bytes.');
                }
            }
        }

        /** True when the outcome proves whether the operation executed (never true for INDETERMINATE). */
        public function isDefinitive(): bool
        {
            return $this->outcome !== TransportOutcome::INDETERMINATE;
        }
    }
}
