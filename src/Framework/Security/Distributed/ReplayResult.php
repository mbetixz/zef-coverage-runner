<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {
    final readonly class ReplayResult
    {
        public function __construct(
            public ReplayDecision $decision,
        ) {
        }

        public function allows(): bool
        {
            return $this->decision === ReplayDecision::NOT_REQUIRED
                || $this->decision === ReplayDecision::ACCEPT;
        }
    }
}
