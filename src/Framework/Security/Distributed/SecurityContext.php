<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {
    final readonly class SecurityContext
    {
        public const int MAX_PRINCIPAL_BYTES = 128;
        public const int MAX_AUTH_METHOD_BYTES = 64;
        public const int MAX_AUTHZ_CONTEXT_BYTES = 256;
        public const int MAX_CREDENTIAL_SCOPE_BYTES = 128;
        public const int MAX_PEER_IDENTITY_BYTES = 128;

        public function __construct(
            public string $principalId,
            public string $authenticationMethod,
            public string $authorizationContext,
            public string $credentialScope,
            public ?string $peerIdentity,
        ) {
            self::assertBounded($principalId, self::MAX_PRINCIPAL_BYTES, 'principalId');
            self::assertBounded($authenticationMethod, self::MAX_AUTH_METHOD_BYTES, 'authenticationMethod');
            self::assertBounded($authorizationContext, self::MAX_AUTHZ_CONTEXT_BYTES, 'authorizationContext');
            self::assertBounded($credentialScope, self::MAX_CREDENTIAL_SCOPE_BYTES, 'credentialScope');
            if ($peerIdentity !== null) {
                self::assertBounded($peerIdentity, self::MAX_PEER_IDENTITY_BYTES, 'peerIdentity');
            }
        }

        private static function assertBounded(string $value, int $maxBytes, string $field): void
        {
            if ($value === '' || strlen($value) > $maxBytes) {
                throw new \InvalidArgumentException($field . ' exceeds its bound.');
            }
        }
    }
}
