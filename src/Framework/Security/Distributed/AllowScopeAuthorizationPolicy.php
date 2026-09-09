<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {

    /**
     * FRAMEWORK-ONLY example authorization policy (M-3).
     *
     * Grants access when the authenticated principal's credential scope covers
     * the required scope (comma-separated) or when $allowAnonymous is set and
     * the principal is the anonymous fallback used for safe methods.
     *
     * Applications replace this with policies derived from the resource/action
     * of SecurityRequest (RBAC/ABAC) before protecting sensitive data.
     */
    final class AllowScopeAuthorizationPolicy implements AuthorizationPolicyInterface
    {
        public function __construct(
            private readonly string $requiredScope,
            private readonly bool $allowAnonymous = false,
        ) {
            if ($requiredScope === '') {
                throw new \InvalidArgumentException('requiredScope must not be empty.');
            }
        }

        #[\Override]
        public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
        {
            $principal = strtolower($context->principalId);
            if ($principal === 'anonymous' && $this->allowAnonymous) {
                return new AuthorizationResult(SecurityVerdict::ALLOW, 'anonymous-allowed');
            }
            $granted = array_filter(array_map('trim', explode(',', $context->credentialScope)), static fn (string $s): bool => $s !== '');
            if (in_array($this->requiredScope, $granted, true)) {
                return new AuthorizationResult(SecurityVerdict::ALLOW, 'scope-granted');
            }
            return new AuthorizationResult(SecurityVerdict::DENY, 'scope-denied');
        }
    }
}
