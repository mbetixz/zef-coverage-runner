<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    final class NoopTracer implements TracerInterface
    {
        #[\Override] public function startSpan(string $name, array $attributes = [], ?SpanContext $parent = null): SpanInterface
        {
            return NoopSpan::instance();
        }
    }
}
