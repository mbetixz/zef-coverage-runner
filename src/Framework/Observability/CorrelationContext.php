<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    /**
     * Immutable, bounded causal context for remote/distributed execution.
     * Correlation is diagnostic/causal only; it is never an authorization or retry signal.
     */
    final readonly class CorrelationContext
    {
        /** @var array<string, string|int|float|bool|null> */
        public readonly array $attributes;

        /**
         * @param array<string, string|int|float|bool|null> $attributes
         */
        public function __construct(
            public string $traceId,
            public string $spanId,
            public string $traceFlags,
            public ?string $traceState,
            public string $operationId,
            public ?string $idempotencyKey = null,
            array $attributes = [],
        ) {
            self::validateTraceId($traceId);
            self::validateSpanId($spanId);
            self::validateTraceFlags($traceFlags);
            self::validateTraceState($traceState);
            self::validateToken($operationId, 'operationId');
            self::validateOptionalToken($idempotencyKey, 'idempotencyKey');
            /** @var array<string, string|int|float|bool|null> $validatedAttributes */
            $validatedAttributes = self::validateAttributes($attributes);
            $this->attributes = $validatedAttributes;

            $wireSize = self::traceParentLength($traceId, $spanId, $traceFlags)
                + ($traceState === null ? 0 : strlen($traceState) + 1);
            if ($wireSize > self::MAX_PROPAGATION_BYTES) {
                throw new \InvalidArgumentException('Correlation propagation exceeds the hard size limit.');
            }
        }

        public const int MAX_PROPAGATION_BYTES = 8192;
        public const int MAX_TRACEPARENT_BYTES = 55;
        public const int MAX_TRACESTATE_BYTES = 512;
        public const int MAX_OPERATION_ID_BYTES = 128;
        public const int MAX_IDEMPOTENCY_KEY_BYTES = 128;
        public const int MAX_ATTRIBUTES = 16;
        public const int MAX_ATTRIBUTE_KEY_BYTES = 64;
        public const int MAX_ATTRIBUTE_VALUE_BYTES = 256;
        public const int MAX_ATTRIBUTE_BYTES = 4096;

        public function traceParent(): string
        {
            return '00-' . $this->traceId . '-' . $this->spanId . '-' . $this->traceFlags;
        }

        public function propagationBytes(): int
        {
            return self::traceParentLength($this->traceId, $this->spanId, $this->traceFlags)
                + ($this->traceState === null ? 0 : strlen($this->traceState) + 1);
        }

        /**
         * Returns only correlation-safe diagnostics. Sensitive correlation values are represented by stable digests.
         * @return array<string, string|int|float|bool|null>
         */
        /** @return array<string, string|int|float|bool|null> */
        public function redactedAttributes(): array
        {
            $out = [];
            foreach ($this->attributes as $key => $value) {
                if (self::isSensitiveKey($key)) {
                    $out[$key] = is_string($value)
                        ? 'sha256:' . hash('sha256', $value)
                        : '[REDACTED]';
                    continue;
                }
                $out[$key] = $value;
            }
            return $out;
        }

        private static function validateTraceId(string $value): void
        {
            if (preg_match('/^[0-9a-f]{32}$/', $value) !== 1 || $value === str_repeat('0', 32)) {
                throw new \InvalidArgumentException('Invalid W3C trace ID.');
            }
        }

        private static function validateSpanId(string $value): void
        {
            if (preg_match('/^[0-9a-f]{16}$/', $value) !== 1 || $value === str_repeat('0', 16)) {
                throw new \InvalidArgumentException('Invalid W3C span ID.');
            }
        }

        private static function validateTraceFlags(string $value): void
        {
            if (preg_match('/^[0-9a-f]{2}$/', $value) !== 1 || ((hexdec($value) & 0xFE) !== 0)) {
                throw new \InvalidArgumentException('Invalid W3C trace flags.');
            }
        }

        private static function validateTraceState(?string $value): void
        {
            if ($value === null) {
                return;
            }
            if ($value === '' || strlen($value) > self::MAX_TRACESTATE_BYTES || preg_match('/^[a-z0-9][_a-z0-9\-*\/]{0,255}=[!#$%&\x27*+\-.\/0-9:<=>?@A-Z\\^_`a-z|~]*(?:, ?[a-z0-9][_a-z0-9\-*\/]{0,255}=[!#$%&\x27*+\-.\/0-9:<=>?@A-Z\\^_`a-z|~]*)*$/', $value) !== 1) {
                throw new \InvalidArgumentException('Invalid or oversized W3C tracestate.');
            }
        }

        private static function validateToken(string $value, string $name): void
        {
            $max = $name === 'operationId' ? self::MAX_OPERATION_ID_BYTES : self::MAX_IDEMPOTENCY_KEY_BYTES;
            if ($value === '' || strlen($value) > $max || preg_match('/^[\x21-\x7E]+$/', $value) !== 1) {
                throw new \InvalidArgumentException(sprintf('%s must be a bounded printable token.', $name));
            }
        }

        private static function validateOptionalToken(?string $value, string $name): void
        {
            if ($value !== null) {
                self::validateToken($value, $name);
            }
        }

        /**
         * @param array<mixed> $attributes
         * @return array<string, string|int|float|bool|null>
         */
        private static function validateAttributes(array $attributes): array
        {
            if (count($attributes) > self::MAX_ATTRIBUTES) {
                throw new \InvalidArgumentException('Correlation attributes exceed the maximum count.');
            }
            $normalized = [];
            $bytes = 0;
            foreach ($attributes as $key => $value) {
                if (!is_string($key) || $key === '' || strlen($key) > self::MAX_ATTRIBUTE_KEY_BYTES || preg_match('/^[A-Za-z0-9_.-]+$/', $key) !== 1) {
                    throw new \InvalidArgumentException('Invalid correlation attribute key.');
                }
                if (!(is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null)) {
                    throw new \InvalidArgumentException('Correlation attribute values must be scalar or null.');
                }
                if (is_string($value) && strlen($value) > self::MAX_ATTRIBUTE_VALUE_BYTES) {
                    throw new \InvalidArgumentException('Correlation attribute string value exceeds the maximum length.');
                }
                $bytes += strlen($key) + self::scalarByteLength($value);
                if ($bytes > self::MAX_ATTRIBUTE_BYTES) {
                    throw new \InvalidArgumentException('Correlation attributes exceed the aggregate size limit.');
                }
                $normalized[$key] = $value;
            }
            return $normalized;
        }

        private static function scalarByteLength(string|int|float|bool|null $value): int
        {
            if ($value === null) {
                return 4;
            }
            if (is_bool($value)) {
                return $value ? 4 : 5;
            }
            if (is_int($value)) {
                return 20;
            }
            if (is_float($value)) {
                return 24;
            }
            return strlen($value);
        }

        private static function traceParentLength(string $traceId, string $spanId, string $flags): int
        {
            return 2 + 1 + strlen($traceId) + 1 + strlen($spanId) + 1 + strlen($flags);
        }

        private static function isSensitiveKey(string $key): bool
        {
            $value = strtolower($key);
            return array_any(['authorization', 'cookie', 'token', 'secret', 'password', 'credential', 'body', 'idempotency'], fn ($needle) => str_contains($value, $needle));
        }
    }
}
