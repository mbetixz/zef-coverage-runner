<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {
    interface AuthorizationPolicyInterface
    {
        public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult;
    }
}
