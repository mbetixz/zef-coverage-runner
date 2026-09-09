<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {
    final readonly class AuthorizationResult
    {
        public function __construct(
            public SecurityVerdict $verdict,
            public string $policyCode,
        ) {
            if ($policyCode === '' || strlen($policyCode) > 128) {
                throw new \InvalidArgumentException('policyCode exceeds its bound.');
            }
        }
    }
}
