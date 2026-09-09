<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    final class CsrfTokenManager
    {
        public function __construct(
            private readonly string $secret,
            private readonly int $tokenBytes = 32,
        ) {
            if (strlen($secret) < 32) {
                throw new \InvalidArgumentException('CSRF secret must be at least 32 bytes.');
            }
            if ($tokenBytes < 16) {
                throw new \InvalidArgumentException('tokenBytes must be >= 16.');
            }
        }

        public function issue(): string
        {
            $tokenBytes = $this->tokenBytes < 16 ? 16 : $this->tokenBytes;
            $token = rtrim(strtr(base64_encode(random_bytes($tokenBytes)), '+/', '-_'), '=');
            $mac = hash_hmac('sha256', $token, $this->secret);
            return $token . '.' . $mac;
        }

        public function isValid(string $token): bool
        {
            [$value, $signature] = array_pad(explode('.', $token, 2), 2, '');
            if ($value === '' || $signature === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
                return false;
            }
            $expected = hash_hmac('sha256', $value, $this->secret);
            return hash_equals($expected, $signature);
        }
    }
}
