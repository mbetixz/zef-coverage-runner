<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {
    final readonly class AuthenticationResult
    {
        public function __construct(
            public AuthenticationStatus $status,
            public ?SecurityContext $context = null,
        ) {
            if ($status === AuthenticationStatus::AUTHENTICATED && $context === null) {
                throw new \InvalidArgumentException('Authenticated result requires a security context.');
            }
            if ($status !== AuthenticationStatus::AUTHENTICATED && $context !== null) {
                throw new \InvalidArgumentException('Non-authenticated result cannot carry a security context.');
            }
        }
    }
}
