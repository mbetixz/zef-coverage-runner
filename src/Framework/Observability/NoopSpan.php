<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    final class NoopSpan implements SpanInterface
    {
        private static ?self $instance = null;
        public static function instance(): self
        {
            return self::$instance ??= new self();
        }
        #[\Override] public function getContext(): SpanContext
        {
            return SpanContext::invalid();
        }
        #[\Override] public function setAttribute(string $key, mixed $value): self
        {
            return $this;
        }
        #[\Override] public function setAttributes(array $attributes): self
        {
            return $this;
        }
        #[\Override] public function addEvent(string $name, array $attributes = []): self
        {
            return $this;
        }
        #[\Override] public function setStatus(string $status, ?string $description = null): self
        {
            return $this;
        }
        #[\Override] public function end(?int $endNs = null): void
        {
        }
        #[\Override] public function isEnded(): bool
        {
            return true;
        }
    }
}
