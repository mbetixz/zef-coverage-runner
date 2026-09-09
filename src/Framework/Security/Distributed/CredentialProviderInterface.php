<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {
    interface CredentialProviderInterface
    {
        public function resolve(CredentialHandle $handle, int $nowMs): AuthenticationResult;
    }
}
