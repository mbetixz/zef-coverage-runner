<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    interface TracerInterface
    {
        /** @param array<string,mixed> $attributes */
        public function startSpan(string $name, array $attributes = [], ?SpanContext $parent = null): SpanInterface;
    }
}
