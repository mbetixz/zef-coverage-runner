<?php

declare(strict_types=1);

namespace Zef\Framework\Transport {
    /**
     * Immutable outbound remote operation request.
     *
     * Bounds (enforced in the constructor): operation is a non-empty string
     * (after trimming) identifying the remote procedure; payload is at most
     * 1 MiB; metadata holds at most 32 entries with non-empty string keys of
     * <= 128 bytes and scalar-or-null values (string values <= 1024 bytes).
     */
    final readonly class RemoteRequest
    {
        /**
         * @param array<int|string, mixed> $metadata
         *
         * @throws \InvalidArgumentException when operation is empty, the payload
         *         exceeds 1 MiB, or a metadata entry violates the bounds above.
         */
        public function __construct(
            public string $operation,
            public string $payload,
            public array $metadata = [],
        ) {
            if (trim($operation) === '') {
                throw new \InvalidArgumentException('Remote operation must not be empty.');
            }
            self::validateMetadata($metadata);
            if (strlen($payload) > 1_048_576) {
                throw new \InvalidArgumentException('Remote payload exceeds the 1 MiB contract limit.');
            }
        }

        /** @param array<int|string, mixed> $metadata */
        private static function validateMetadata(array $metadata): void
        {
            if (count($metadata) > 32) {
                throw new \InvalidArgumentException('Remote metadata may contain at most 32 entries.');
            }
            foreach ($metadata as $key => $value) {
                if (!is_string($key) || trim($key) === '' || strlen($key) > 128) {
                    throw new \InvalidArgumentException('Remote metadata keys must be non-empty strings <= 128 bytes.');
                }
                if ($value !== null && !is_scalar($value)) {
                    throw new \InvalidArgumentException('Remote metadata values must be scalar or null.');
                }
                if (is_string($value) && strlen($value) > 1024) {
                    throw new \InvalidArgumentException('Remote metadata string values may not exceed 1024 bytes.');
                }
            }
        }
    }
}
