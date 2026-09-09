<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\MiddlewareInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Http\Response;

    /**
     * Enforces the SecurityPolicy at runtime: request-id normalization,
     * trusted-proxy-aware client address resolution, optional rate limiting,
     * optional Origin allowlisting, and optional CSRF double-submit checking.
     *
     * Header consumption/emission contract:
     * - X-Request-ID (request/response): the incoming value is accepted only
     *   when it is 1-128 chars of [A-Za-z0-9._:-]; anything absent, longer or
     *   out-of-pattern is replaced with a fresh 32-hex id (never echoed raw),
     *   which is then forced back onto the response via withHeader().
     * - Origin (request): read once; absent/empty maps to null context origin,
     *   which OriginPolicy::assertAllowed() treats as allowed (non-browser
     *   clients). Normalization/sanitization of the value happens inside
     *   OriginPolicy::normalizeOrigin() before any comparison.
     * - X-Forwarded-For (request): consulted by ClientAddressResolver only when
     *   REMOTE_ADDR is a configured trusted proxy; every candidate must pass
     *   FILTER_VALIDATE_IP before being returned.
     * - Cookie (request): parsed read-only by cookieValue() for the CSRF token.
     * - Set-Cookie (response): built solely from policy-controlled attributes
     *   (name, Path=/, SameSite, optional Secure only when the request scheme
     *   is https, optional HttpOnly) plus the fresh CSRF token — no request
     *   input is embedded.
     * - Retry-After / X-RateLimit-* (response): generated from RateLimitDecision
     *   integer fields, cast to string at emission.
     */
    final class SecurityRuntimeMiddleware implements MiddlewareInterface
    {
        private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];
        private readonly ?CsrfTokenManager $csrf;

        /** @param list<string> $trustedProxies */
        public function __construct(
            private readonly SecurityPolicy $policy,
            private readonly RateLimiterInterface $rateLimiter,
            private readonly array $trustedProxies = [],
        ) {
            $this->csrf = $policy->csrfEnabled && $policy->csrfSecret !== '' ? new CsrfTokenManager($policy->csrfSecret, $policy->csrfTokenBytes) : null;
        }

        #[\Override] public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $method = strtoupper($request->getMethod());
            $requestId = $request->getHeaderLine('X-Request-ID');
            if ($requestId === '' || strlen($requestId) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $requestId) !== 1) {
                $requestId = bin2hex(random_bytes(16));
            }
            $requestTrustedProxies = $request->getAttribute('__zef_trusted_proxies', $this->trustedProxies);
            $trustedProxies = is_array($requestTrustedProxies) ? array_values(array_filter($requestTrustedProxies, 'is_string')) : $this->trustedProxies;
            $context = new SecurityContext($requestId, ClientAddressResolver::resolve($request, $trustedProxies), $request->getHeaderLine('Origin') ?: null, strtolower((string) ($request->getUri()->getScheme())) === 'https');
            $request = $request->withAttribute('zef.security.context', $context);

            $rateDecision = null;
            if ($this->policy->rateLimitEnabled) {
                try {
                    $rateDecision = $this->rateLimiter->check($context->clientIp, $this->policy->rateLimitMaxRequests, $this->policy->rateLimitWindowSeconds);
                } catch (\Throwable) {
                    return new Response(503, ['Content-Type' => 'application/json', 'Retry-After' => '1', 'X-Request-ID' => $requestId], '{"error":"Service Unavailable","status":503,"correlation_id":"' . $requestId . '"}');
                }
                if (!$rateDecision->allowed) {
                    return new Response(429, [
                        'Content-Type' => 'application/json',
                        'Retry-After' => (string) $rateDecision->retryAfter,
                        'X-RateLimit-Limit' => (string) $rateDecision->limit,
                        'X-RateLimit-Remaining' => '0',
                        'X-Request-ID' => $requestId,
                    ], '{"error":"Too Many Requests","status":429,"correlation_id":"' . $requestId . '"}');
                }
            }

            if ($this->policy->originEnabled) {
                try {
                    OriginPolicy::assertAllowed($context->origin, $this->policy->allowedOrigins);
                } catch (\Throwable) {
                    return new Response(403, ['Content-Type' => 'application/json', 'X-Request-ID' => $requestId], '{"error":"Forbidden","status":403,"reason":"Origin denied"}');
                }
            }

            $setCookie = null;
            if ($this->csrf !== null) {
                $cookieToken = $this->cookieValue($request->getHeaderLine('Cookie'), $this->policy->csrfCookieName);
                if (in_array($method, self::SAFE_METHODS, true)) {
                    if ($cookieToken === null) {
                        $setCookie = $this->csrf->issue();
                    }
                } else {
                    $headerToken = $request->getHeaderLine($this->policy->csrfHeaderName);
                    if ($cookieToken === null || $headerToken === '' || !$this->csrf->isValid($cookieToken) || !hash_equals($cookieToken, $headerToken)) {
                        return new Response(403, ['Content-Type' => 'application/json', 'X-Request-ID' => $requestId], '{"error":"Forbidden","status":403,"reason":"CSRF validation failed"}');
                    }
                }
            }

            $response = $handler->handle($request)->withHeader('X-Request-ID', $requestId);
            if ($rateDecision !== null) {
                $response = $response
                    ->withHeader('X-RateLimit-Limit', (string) $rateDecision->limit)
                    ->withHeader('X-RateLimit-Remaining', (string) $rateDecision->remaining);
            }
            if ($setCookie !== null) {
                $attributes = [$this->policy->csrfCookieName . '=' . $setCookie, 'Path=/', 'SameSite=' . $this->policy->csrfSameSite];
                if ($this->policy->csrfSecureCookie && $context->secure) {
                    $attributes[] = 'Secure';
                }
                if ($this->policy->csrfHttpOnlyCookie) {
                    $attributes[] = 'HttpOnly';
                }
                $response = $response->withHeader('Set-Cookie', implode('; ', $attributes));
            }
            return $response;
        }

        /**
         * Extracts a single cookie value from a raw Cookie header.
         *
         * Pairs are split on ';' and trimmed; each pair is split on the first
         * '=' only (values may themselves contain '='), and the key is
         * compared byte-for-byte with $name. Returns null when the header is
         * empty or the cookie is not present. Empty values are returned as ''
         * (distinct from absent).
         */
        private function cookieValue(string $header, string $name): ?string
        {
            foreach (explode(';', $header) as $pair) {
                [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
                if ($key === $name) {
                    return trim($value);
                }
            }
            return null;
        }
    }
}
