<?php

declare(strict_types=1);

namespace Zef\Middleware {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\MiddlewareInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Http\Response;

    /**
     * CORS middleware with explicit origin allowlist (audit M-5).
     *
     * - `$allowedOrigins` is a LIST of origins. Only a request whose Origin
     *   header exactly matches an allowlisted origin receives CORS headers.
     *   Origins are normalized via normalizeOrigin() before comparison, so the
     *   allowlist accepts 'https://example.com' or 'https://example.com:8443'
     *   (scheme+host+non-default-port, lower-cased; default ports elided).
     * - Default (no allowlist) is DENY: no Access-Control-Allow-Origin header
     *   is emitted, so browsers keep same-origin policy for cross-site calls.
     * - `$allowAll` ('*' mode) may only be used for public, credential-less
     *   endpoints and is never combined with credentials.
     * - OPTIONS preflight is answered 204 when the origin is allowed.
     *
     * Backwards compatibility: the previous single-string constructor argument
     * is interpreted as a one-entry allowlist; passing null/'' keeps deny.
     */
    final class CorsMiddleware implements MiddlewareInterface
    {
        /** @var list<string> */
        private readonly array $allowedOrigins;
        private readonly bool $allowAll;

        /** @param list<string>|string|null $allowOrigin */
        public function __construct(
            array|string|null $allowOrigin = null,
            private readonly string $allowMethods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            private readonly string $allowHeaders = 'Content-Type, Authorization, X-Request-ID',
        ) {
            $origins = is_array($allowOrigin) ? $allowOrigin : [$allowOrigin];
            $normalized = [];
            foreach ($origins as $origin) {
                if (!is_string($origin)) {
                    continue;
                }
                $origin = trim($origin);
                if ($origin === '') {
                    continue;
                }
                if ($origin === '*') {
                    $this->allowAll = true;
                    $this->allowedOrigins = ['*'];
                    return;
                }
                $normalized[] = $this->normalizeOrigin($origin);
            }
            $this->allowAll = false;
            $this->allowedOrigins = array_values(array_unique($normalized));
        }

        #[\Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            if ($this->allowedOrigins === []) {
                return $handler->handle($request);
            }

            $requestOrigin = trim($request->getHeaderLine('Origin'));
            $originAllowed = $this->allowAll || ($requestOrigin !== '' && $this->originIsAllowed($requestOrigin));

            // PSR-7 forbids CR/LF inside header values, so a request Origin
            // can never smuggle a line break into the echoed ACAO header; the
            // structural allowlist checks below are the only validation needed.

            if ($this->allowAll) {
                // '*' mode is only safe for public, credential-less endpoints.
                $originAllowed = true;
            } elseif ($requestOrigin === '') {
                // Non-browser clients (curl, server-to-server) send no Origin.
                // They are not subject to CORS; pass through without headers.
                return $handler->handle($request);
            } elseif (!$originAllowed) {
                // Cross-site request from a non-allowlisted origin: pass the
                // request through but never attach CORS headers, so the browser
                // blocks the response from being read cross-origin. Preflights
                // from unknown origins are rejected explicitly.
                if (strtoupper($request->getMethod()) === 'OPTIONS') {
                    return new Response(204, ['Vary' => 'Origin']);
                }
                return $handler->handle($request);
            }

            $allowOrigin = $this->allowAll ? '*' : $requestOrigin;
            if (strtoupper($request->getMethod()) === 'OPTIONS') {
                return new Response(204, [
                    'Access-Control-Allow-Origin' => $allowOrigin,
                    'Access-Control-Allow-Methods' => $this->allowMethods,
                    'Access-Control-Allow-Headers' => $this->allowHeaders,
                    'Access-Control-Max-Age' => '600',
                    'Vary' => 'Origin',
                ]);
            }

            $response = $handler->handle($request)
                ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
                ->withHeader('Access-Control-Allow-Methods', $this->allowMethods)
                ->withHeader('Access-Control-Allow-Headers', $this->allowHeaders);
            // Normalize the accumulated Vary header: split on commas, trim,
            // dedupe case-insensitively, and always (re)append Origin so the
            // response stays cache-correct for allowed-origin negotiation.
            $vary = $response->getHeader('Vary');
            $tokens = [];
            foreach ($vary as $line) {
                foreach (explode(',', $line) as $token) {
                    $token = trim($token);
                    if ($token !== '') {
                        $tokens[strtolower($token)] = $token;
                    }
                }
            }
            $tokens['origin'] = 'Origin';
            return $response->withHeader('Vary', implode(', ', array_values($tokens)));
        }

        private function originIsAllowed(string $origin): bool
        {
            try {
                $normalized = $this->normalizeOrigin($origin);
            } catch (\InvalidArgumentException) {
                return false;
            }
            return in_array($normalized, $this->allowedOrigins, true);
        }

        /**
         * Canonicalizes an Origin header value for allowlist comparison.
         * Returns 'null' for the literal serialization, '' for values that
         * contain CR/LF (rejected downstream), and scheme://host[:port] for
         * well-formed http(s) origins.
         *
         * @throws \InvalidArgumentException on structurally malformed origins
         */
        private function normalizeOrigin(string $origin): string
        {
            $origin = trim($origin);
            if ($origin === 'null' || preg_match('/[\r\n]/', $origin) === 1) {
                return $origin === 'null' ? 'null' : '';
            }
            $parts = parse_url($origin);
            if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['user'], $parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new \InvalidArgumentException('Malformed Origin header.');
            }
            if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
                throw new \InvalidArgumentException('Malformed Origin header.');
            }
            $scheme = strtolower((string) $parts['scheme']);
            $host = strtolower((string) $parts['host']);
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new \InvalidArgumentException('Unsupported Origin scheme.');
            }
            $port = isset($parts['port']) ? (int) $parts['port'] : null;
            if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
                $port = null;
            }
            return $scheme . '://' . $host . ($port === null ? '' : ':' . $port);
        }
    }
}
