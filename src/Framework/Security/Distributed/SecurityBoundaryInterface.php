<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {
    interface SecurityBoundaryInterface
    {
        public function admit(
            AuthenticationResult $authentication,
            SecurityRequest $request,
            AuthorizationPolicyInterface $authorization,
            ReplayProtectorInterface $replayProtector,
            int $nowMs,
        ): SecurityAdmissionDecision;
    }
}
