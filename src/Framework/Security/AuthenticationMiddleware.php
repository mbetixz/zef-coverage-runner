<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\MiddlewareInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Http\Response;
    use Zef\Framework\Security\Distributed\AuthorizationPolicyInterface;
    use Zef\Framework\Security\Distributed\CredentialHandle;
    use Zef\Framework\Security\Distributed\CredentialProviderInterface;
    use Zef\Framework\Security\Distributed\ReplayProtectorInterface;
    use Zef\Framework\Security\Distributed\SecurityBoundaryInterface;
    use Zef\Framework\Security\Distributed\SecurityRequest;

    /**
     * PSR-15 entrypoint that protects state-mutating requests with the
     * distributed SecurityBoundary (framework-only, M-3).
     *
     * The middleware extracts a bearer credential from the Authorization
     * header, resolves it through the injected CredentialProviderInterface,
     * and admits the request through SecurityBoundaryInterface::admit() using
     * the application-supplied AuthorizationPolicyInterface and
     * ReplayProtectorInterface. Applications wire concrete providers/policies
     * (e.g. a JWT/DB-backed credential provider) through the container.
     *
     * Routes may opt out of admission by not being routed through this
     * middleware; route-level protection is the application's responsibility.
     *
     * Header consumption contract:
     * - Authorization (request): optional bearer credential. Accepted only when
     *   the value matches `/^Bearer\s+(.+)$/i`; the captured token is trimmed
     *   and rejected when empty or longer than 512 bytes. A missing or
     *   malformed header simply yields no credential (anonymous), which is
     *   denied with 401 for state-mutating (non-safe) methods only.
     * - X-Replay-Id (request): read once via getHeaderLine(); an absent header
     *   yields '' and is mapped to null replay protection. The raw value is
     *   never echoed back into any response header.
     * - X-Request-ID (response, deny() only): reuses the request-scoped id from
     *   the 'zef.security.context' attribute when present, otherwise a fresh
     *   16-hex random id. It is a generated/tracked value, not attacker input.
     */
    final class AuthenticationMiddleware implements MiddlewareInterface
    {
        public function __construct(
            private readonly CredentialProviderInterface $credentialProvider,
            private readonly AuthorizationPolicyInterface $authorization,
            private readonly ReplayProtectorInterface $replayProtector,
            private readonly SecurityBoundaryInterface $boundary,
        ) {
        }

        #[\Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $method = strtoupper($request->getMethod());
            $safe = in_array($method, ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true);

            $credential = $this->extractCredential($request);
            if (!$safe && $credential === null) {
                return $this->deny(401, 'Authentication required', $request);
            }

            $authentication = $this->credentialProvider->resolve(
                $credential ?? new CredentialHandle('anonymous', 'public', 0),
                (int) (microtime(true) * 1000),
            );

            $securityRequest = new SecurityRequest(
                operationClass: $method . ' ' . (string) $request->getUri()->getPath(),
                resource: $this->resourceFromRequest($request),
                action: $method,
                replayId: $request->getHeaderLine('X-Replay-Id') ?: null,
            );

            $admission = $this->boundary->admit(
                $authentication,
                $securityRequest,
                $this->authorization,
                $this->replayProtector,
                (int) (microtime(true) * 1000),
            );

            if (!$admission->allows()) {
                $status = $authentication->context === null ? 401 : 403;
                return $this->deny($status, $admission->failure->value, $request);
            }

            $request = $request->withAttribute('zef.security.principal', $authentication->context->principalId ?? 'anonymous');
            return $handler->handle($request);
        }

        /**
         * Parses a Bearer credential from the Authorization header.
         *
         * Returns null (no credential) when the header is absent/empty, does
         * not match the Bearer scheme, or the trimmed token is empty or longer
         * than 512 bytes. Comparison is case-insensitive for the scheme only
         * ('/i'); the token itself is passed through verbatim (trimmed) and is
         * validated downstream by the CredentialProviderInterface.
         */
        private function extractCredential(ServerRequestInterface $request): ?CredentialHandle
        {
            $authorization = $request->getHeaderLine('Authorization');
            if ($authorization === '' || !preg_match('/^Bearer\\s+(.+)$/i', $authorization, $m)) {
                return null;
            }
            $token = trim($m[1]);
            if ($token === '' || strlen($token) > 512) {
                return null;
            }
            return new CredentialHandle($token, 'bearer', PHP_INT_MAX);
        }

        private function resourceFromRequest(ServerRequestInterface $request): string
        {
            $path = (string) $request->getUri()->getPath();
            if (strlen($path) > 128) {
                $path = substr($path, 0, 128);
            }
            return $path === '' ? '/' : $path;
        }

        /**
         * Builds a JSON error response without invoking the application.
         *
         * The X-Request-ID header is taken from the 'zef.security.context'
         * attribute when a SecurityContext is present (earlier middleware in
         * the stack), falling back to a fresh 8-byte random hex id otherwise.
         * It is a generated/tracked value only — never derived from request
         * input — so no sanitization is required at this point.
         */
        private function deny(int $status, string $reason, ServerRequestInterface $request): ResponseInterface
        {
            $requestId = $request->getAttribute('zef.security.context') instanceof \Zef\Framework\Security\SecurityContext
                ? (string) $request->getAttribute('zef.security.context')->requestId
                : bin2hex(random_bytes(8));
            return new Response(
                $status,
                ['Content-Type' => 'application/json', 'X-Request-ID' => $requestId],
                json_encode(['error' => $reason, 'status' => $status, 'correlation_id' => $requestId], JSON_THROW_ON_ERROR),
            );
        }
    }
}
