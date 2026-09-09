<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    interface SpanInterface
    {
        public function getContext(): SpanContext;
        /** @param mixed $value */
        public function setAttribute(string $key, mixed $value): self;
        /** @param array<string,mixed> $attributes */
        public function setAttributes(array $attributes): self;
        /** @param array<string,mixed> $attributes */
        public function addEvent(string $name, array $attributes = []): self;
        public function setStatus(string $status, ?string $description = null): self;
        public function end(?int $endNs = null): void;
        public function isEnded(): bool;
    }
}
