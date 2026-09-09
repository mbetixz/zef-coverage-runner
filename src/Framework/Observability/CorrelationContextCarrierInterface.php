<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    /** Explicit context carrier; no mutable global/request-local state is used. */
    interface CorrelationContextCarrierInterface
    {
        public function correlationContext(): ?CorrelationContext;
    }
}
