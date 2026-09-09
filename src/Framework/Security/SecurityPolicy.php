<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    final readonly class SecurityPolicy
    {
        /** @var list<string> */
        public array $allowedOrigins;

        /** @param list<string> $allowedOrigins */
        public function __construct(
            public bool $rateLimitEnabled = false,
            public int $rateLimitMaxRequests = 100,
            public int $rateLimitWindowSeconds = 60,
            public int $rateLimitMaxKeys = 10000,
            public bool $csrfEnabled = true,
            public string $csrfSecret = '',
            public string $csrfCookieName = 'ZEF-XSRF-TOKEN',
            public string $csrfHeaderName = 'X-CSRF-Token',
            public bool $csrfSecureCookie = true,
            public bool $csrfHttpOnlyCookie = true,
            public string $csrfSameSite = 'Strict',
            array $allowedOrigins = [],
            public bool $originEnabled = false,
            public int $csrfTokenBytes = 32,
        ) {
            if ($this->csrfTokenBytes < 16) {
                throw new \InvalidArgumentException('csrfTokenBytes must be >= 16.');
            }
            if ($this->rateLimitMaxRequests < 1) {
                throw new \InvalidArgumentException('rateLimitMaxRequests must be >= 1.');
            }
            if ($this->rateLimitWindowSeconds < 1) {
                throw new \InvalidArgumentException('rateLimitWindowSeconds must be >= 1.');
            }
            if ($this->rateLimitMaxKeys < 1) {
                throw new \InvalidArgumentException('rateLimitMaxKeys must be >= 1.');
            }
            if ($this->csrfEnabled && $this->csrfSecret !== '' && strlen($this->csrfSecret) < 32) {
                throw new \InvalidArgumentException('CSRF secret must be at least 32 bytes when CSRF is enabled.');
            }
            if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $this->csrfCookieName)) {
                throw new \InvalidArgumentException('Invalid CSRF cookie name.');
            }
            if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $this->csrfHeaderName)) {
                throw new \InvalidArgumentException('Invalid CSRF header name.');
            }
            if (!in_array($this->csrfSameSite, ['Strict', 'Lax', 'None'], true)) {
                throw new \InvalidArgumentException('Invalid CSRF SameSite policy.');
            }
            if ($this->csrfSameSite === 'None' && !$this->csrfSecureCookie) {
                throw new \InvalidArgumentException('SameSite=None requires Secure cookies.');
            }
            $normalized = [];
            foreach ($allowedOrigins as $origin) {
                $normalized[] = OriginPolicy::normalizeOrigin($origin);
            }
            $this->allowedOrigins = array_values(array_unique($normalized));
            if ($this->originEnabled && $this->allowedOrigins === []) {
                throw new \InvalidArgumentException('Origin policy enabled without allowed origins.');
            }
        }

        public static function fromEnvironment(): self
        {
            $bool = static function (string $name, bool $default = false): bool {
                $value = getenv($name);
                return $value === false ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
            };
            // CSRF is enabled by default (fail-safe). A deployment must set
            // ZEF_SECURITY_CSRF=0 explicitly to disable state-mutation
            // protection; an absent variable never disables it silently.
            $csrfDefault = true;
            $csrfEnv = getenv('ZEF_SECURITY_CSRF');
            if ($csrfEnv !== false && trim((string) $csrfEnv) === '') {
                $csrfEnv = false;
            }
            $csrfEnabled = $csrfEnv === false ? $csrfDefault : filter_var($csrfEnv, FILTER_VALIDATE_BOOL);
            $csrfSecret = (string) (getenv('ZEF_SECURITY_CSRF_SECRET') ?: '');
            if ($csrfEnabled && $csrfSecret === '' && $csrfEnv !== false && filter_var($csrfEnv, FILTER_VALIDATE_BOOL)) {
                // Explicit opt-in without a usable secret must never silently
                // degrade to unprotected state mutations: fail loudly.
                throw new \RuntimeException('ZEF_SECURITY_CSRF=1 requires ZEF_SECURITY_CSRF_SECRET (>= 32 bytes).');
            }
            if ($csrfEnabled && $csrfSecret === '') {
                // CSRF is on by default, but a deployment that provides no
                // secret cannot issue validatable tokens. Fail-safe: disable
                // CSRF protection and surface a prominent runtime warning
                // instead of allowing an attacker-chosen secret of zero length.
                $csrfEnabled = false;
                error_log('[ZEF][security] ZEF_SECURITY_CSRF_SECRET is not set; CSRF protection disabled. Set a secret of at least 32 bytes in production.');
            }
            $csv = static function (string $name): array {
                $value = getenv($name);
                if ($value === false || trim($value) === '') {
                    return [];
                }
                return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
            };
            return new self(
                rateLimitEnabled: $bool('ZEF_SECURITY_RATE_LIMIT'),
                rateLimitMaxRequests: max(1, (int) (getenv('ZEF_SECURITY_RATE_LIMIT_MAX') ?: 100)),
                rateLimitWindowSeconds: max(1, (int) (getenv('ZEF_SECURITY_RATE_LIMIT_WINDOW') ?: 60)),
                rateLimitMaxKeys: max(1, (int) (getenv('ZEF_SECURITY_RATE_LIMIT_MAX_KEYS') ?: 10000)),
                csrfEnabled: $csrfEnabled,
                csrfSecret: $csrfSecret,
                csrfCookieName: trim((string) (getenv('ZEF_SECURITY_CSRF_COOKIE') ?: 'ZEF-XSRF-TOKEN')),
                csrfHeaderName: trim((string) (getenv('ZEF_SECURITY_CSRF_HEADER') ?: 'X-CSRF-Token')),
                csrfSecureCookie: $bool('ZEF_SECURITY_CSRF_SECURE', true),
                csrfHttpOnlyCookie: $bool('ZEF_SECURITY_CSRF_HTTP_ONLY', true),
                csrfSameSite: trim((string) (getenv('ZEF_SECURITY_CSRF_SAMESITE') ?: 'Strict')),
                allowedOrigins: $csv('ZEF_SECURITY_ALLOWED_ORIGINS'),
                originEnabled: $bool('ZEF_SECURITY_ORIGIN_POLICY'),
                csrfTokenBytes: max(16, (int) (getenv('ZEF_SECURITY_CSRF_TOKEN_BYTES') ?: 32)),
            );
        }
    }
}
