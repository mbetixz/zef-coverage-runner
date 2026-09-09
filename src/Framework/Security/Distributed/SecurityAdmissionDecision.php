<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {
    final readonly class SecurityAdmissionDecision
    {
        public function __construct(
            public SecurityVerdict $verdict,
            public SecurityFailure $failure,
            public bool $retryAllowed,
        ) {
            if ($verdict === SecurityVerdict::ALLOW && $failure !== SecurityFailure::NONE) {
                throw new \InvalidArgumentException('Allowed security decision cannot carry failure.');
            }
            if ($verdict === SecurityVerdict::DENY && $retryAllowed) {
                throw new \InvalidArgumentException('Denied security decision cannot allow retry.');
            }
        }

        public function allows(): bool
        {
            return $this->verdict === SecurityVerdict::ALLOW;
        }
    }
}
