<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    final class SpanData
    {
        /**
         * @param array<string,mixed> $attributes
         * @param list<array{name:string,time_unix_nano:int,attributes:array<string,mixed>}> $events
         */
        public function __construct(
            public readonly string $name,
            public readonly SpanContext $context,
            public readonly ?SpanContext $parent,
            public readonly int $startNs,
            public readonly int $endNs,
            public readonly int $startUnixNano,
            public readonly int $endUnixNano,
            public readonly string $status,
            public readonly ?string $statusDescription,
            public readonly array $attributes,
            public readonly array $events,
        ) {
        }

        public function durationSeconds(): float
        {
            return max(0, $this->endNs - $this->startNs) / 1_000_000_000;
        }
    }
}
