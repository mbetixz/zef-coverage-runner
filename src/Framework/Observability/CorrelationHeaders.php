<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    /** Immutable transport headers for correlation propagation. */
    final readonly class CorrelationHeaders
    {
        public function __construct(
            public string $traceParent,
            public ?string $traceState,
        ) {
            if (strlen($traceParent) !== CorrelationContext::MAX_TRACEPARENT_BYTES) {
                throw new \InvalidArgumentException('Correlation traceparent must be 55 bytes.');
            }
            if ($traceState !== null && strlen($traceState) > CorrelationContext::MAX_TRACESTATE_BYTES) {
                throw new \InvalidArgumentException('Correlation tracestate exceeds the hard limit.');
            }
        }

        public function encodedBytes(): int
        {
            return strlen($this->traceParent) + ($this->traceState === null ? 0 : strlen($this->traceState));
        }
    }
}
