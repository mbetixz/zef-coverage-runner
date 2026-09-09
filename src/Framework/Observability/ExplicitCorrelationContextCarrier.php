<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    final readonly class ExplicitCorrelationContextCarrier implements CorrelationContextCarrierInterface
    {
        public function __construct(private ?CorrelationContext $context)
        {
        }
        #[\Override] public function correlationContext(): ?CorrelationContext
        {
            return $this->context;
        }
    }
}
