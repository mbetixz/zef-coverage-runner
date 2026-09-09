<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {

    /**
     * STATIC, FRAMEWORK-ONLY stub credential provider (M-3).
     *
     * Demonstrates the CredentialProviderInterface contract and lets
     * applications boot an end-to-end protected route without a real user
     * store. It treats every bearer token that matches the configured static
     * token as the configured principal.
     *
     * WARNING: hard-coded tokens are NOT acceptable in production. Wire a real
     * provider (database/JWT/OIDC) that resolves CredentialHandles to
     * identities before exposing data endpoints.
     */
    final class StaticCredentialProvider implements CredentialProviderInterface
    {
        /** @param list<string> $validTokens */
        public function __construct(
            private readonly array $validTokens,
            private readonly string $principalId = 'static-principal',
            private readonly string $scope = 'api',
            private readonly int $expiresAtMs = 0,
        ) {
            if ($validTokens === []) {
                throw new \InvalidArgumentException('StaticCredentialProvider requires at least one valid token.');
            }
        }

        #[\Override]
        public function resolve(CredentialHandle $handle, int $nowMs): AuthenticationResult
        {
            if (!in_array($handle->handleId, $this->validTokens, true)) {
                return new AuthenticationResult(AuthenticationStatus::FAILED);
            }
            if ($this->expiresAtMs > 0 && $nowMs > $this->expiresAtMs) {
                return new AuthenticationResult(AuthenticationStatus::EXPIRED);
            }
            return new AuthenticationResult(
                AuthenticationStatus::AUTHENTICATED,
                new SecurityContext(
                    principalId: $this->principalId,
                    authenticationMethod: 'static-bearer',
                    authorizationContext: 'route',
                    credentialScope: $this->scope,
                    peerIdentity: null,
                ),
            );
        }
    }
}
