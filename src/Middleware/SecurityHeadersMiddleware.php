<?php

declare(strict_types=1);

namespace Zef\Middleware {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\MiddlewareInterface;
    use Psr\Http\Server\RequestHandlerInterface;

    /**
     * Applies hardened, immutable security headers to every response.
     *
     * Always applied: X-Content-Type-Options (nosniff), X-Frame-Options
     * (DENY), Referrer-Policy (no-referrer), X-Permitted-Cross-Domain-Policies
     * (none), Cross-Origin-Opener-Policy (same-origin) and
     * Cross-Origin-Resource-Policy (same-origin). Optional toggles (all
     * default-off): Permissions-Policy, Content-Security-Policy (with an
     * explicit policy string) and HSTS (emitted only over https).
     *
     * @phpstan-type SecurityHeaderPolicy array{hsts?:bool,csp?:bool,contentSecurityPolicy?:string,permissionsPolicy?:bool}
     *
     * @param SecurityHeaderPolicy $policy
     */
    final class SecurityHeadersMiddleware implements MiddlewareInterface
    {
        /** @param array{hsts?:bool,csp?:bool,contentSecurityPolicy?:string,permissionsPolicy?:bool} $policy */
        public function __construct(private readonly array $policy = [])
        {
        }

        #[\Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $response = $handler->handle($request)
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('X-Frame-Options', 'DENY')
                ->withHeader('Referrer-Policy', 'no-referrer')
                ->withHeader('X-Permitted-Cross-Domain-Policies', 'none')
                ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
                ->withHeader('Cross-Origin-Resource-Policy', 'same-origin');
            if (($this->policy['permissionsPolicy'] ?? true) === true) {
                $response = $response->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
            }
            if (($this->policy['csp'] ?? false) === true) {
                $response = $response->withHeader('Content-Security-Policy', (string) ($this->policy['contentSecurityPolicy'] ?? "default-src 'self'; frame-ancestors 'none'; base-uri 'self'"));
            }
            if (($this->policy['hsts'] ?? false) === true && strtolower($request->getUri()->getScheme()) === 'https') {
                $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            }
            return $response;
        }
    }
}
