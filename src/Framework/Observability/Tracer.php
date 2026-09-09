<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    final class Tracer implements TracerInterface
    {
        public function __construct(private readonly BatchSpanProcessor $processor, private readonly bool $enabled = true)
        {
        }
        #[\Override] public function startSpan(string $name, array $attributes = [], ?SpanContext $parent = null): SpanInterface
        {
            if (!$this->enabled) {
                return NoopSpan::instance();
            }
            $traceId = $parent->traceId ?? bin2hex(random_bytes(16));
            $spanId = bin2hex(random_bytes(8));
            $context = new SpanContext($traceId, $spanId, true);
            return new Span($name, $context, $parent, TelemetryClock::nowNs(), TelemetryClock::nowUnixNano(), $this->processor->onEnd(...), $attributes);
        }
    }
}
