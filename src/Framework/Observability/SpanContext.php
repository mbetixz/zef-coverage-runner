<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    final readonly class SpanContext
    {
        public function __construct(
            public string $traceId,
            public string $spanId,
            public bool $sampled = true,
            public ?string $traceState = null,
        ) {
            if (preg_match('/^[0-9a-f]{32}$/', $traceId) !== 1 || preg_match('/^[0-9a-f]{16}$/', $spanId) !== 1) {
                throw new \InvalidArgumentException('Invalid W3C trace context identifiers.');
            }
        }

        public function traceParent(): string
        {
            return '00-' . $this->traceId . '-' . $this->spanId . '-' . ($this->sampled ? '01' : '00');
        }

        public static function invalid(): self
        {
            return new self(str_repeat('0', 32), str_repeat('0', 16), false);
        }

        public function isValid(): bool
        {
            return $this->traceId !== str_repeat('0', 32) && $this->spanId !== str_repeat('0', 16);
        }
    }
}
