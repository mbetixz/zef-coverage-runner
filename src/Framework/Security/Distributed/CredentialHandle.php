<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {
    final readonly class CredentialHandle
    {
        public const int MAX_ID_BYTES = 128;
        public const int MAX_SCOPE_BYTES = 128;

        public function __construct(
            public string $handleId,
            public string $scope,
            public int $expiresAtMs,
        ) {
            self::assertBounded($handleId, self::MAX_ID_BYTES, 'handleId');
            self::assertBounded($scope, self::MAX_SCOPE_BYTES, 'scope');
            if ($expiresAtMs < 0) {
                throw new \InvalidArgumentException('expiresAtMs must be >= 0.');
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
