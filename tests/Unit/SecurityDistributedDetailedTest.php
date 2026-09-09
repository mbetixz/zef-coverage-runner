<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Security\AuthenticationMiddleware;
use Zef\Framework\Security\ClientAddressResolver;
use Zef\Framework\Security\CsrfTokenManager;
use Zef\Framework\Security\Distributed\AllowScopeAuthorizationPolicy;
use Zef\Framework\Security\Distributed\AuthenticationResult;
use Zef\Framework\Security\Distributed\AuthenticationStatus;
use Zef\Framework\Security\Distributed\AuthorizationResult;
use Zef\Framework\Security\Distributed\BoundedInMemoryReplayProtector;
use Zef\Framework\Security\Distributed\CredentialHandle;
use Zef\Framework\Security\Distributed\DefaultSecurityBoundary;
use Zef\Framework\Security\Distributed\ReplayDecision;
use Zef\Framework\Security\Distributed\SecurityAdmissionDecision;
use Zef\Framework\Security\Distributed\SecurityContext;
use Zef\Framework\Security\Distributed\SecurityFailure;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\Distributed\SecurityVerdict;
use Zef\Framework\Security\Distributed\StaticCredentialProvider;
use Zef\Framework\Security\OriginPolicy;
use Zef\Framework\Security\SecurityContext as HttpSecurityContext;

/**
 * Detailed coverage for Security\Distributed building blocks plus the
 * framework security surface: credential providers, authorization policies,
 * replay protection, boundary admission, the PSR-15 AuthenticationMiddleware,
 * ClientAddressResolver, OriginPolicy and CsrfTokenManager.
 */
final class SecurityDistributedDetailedTest extends TestCase
{
    /** @return array<string, string> */
    private static function manyAttributes(int $count): array
    {
        $attributes = [];
        for ($i = 0; $i < $count; ++$i) {
            $attributes['attr' . $i] = 'v';
        }
        return $attributes;
    }

    // ------------------------------------------------------------------
    // StaticCredentialProvider
    // ------------------------------------------------------------------

    public function testStaticProviderAuthenticatesKnownToken(): void
    {
        $provider = new StaticCredentialProvider(['tok-1', 'tok-2'], principalId: 'svc-a', scope: 'api');
        $result = $provider->resolve(new CredentialHandle('tok-2', 'bearer', PHP_INT_MAX), 5_000);
        self::assertSame(AuthenticationStatus::AUTHENTICATED, $result->status);
        self::assertNotNull($result->context);
        self::assertSame('svc-a', $result->context->principalId);
        self::assertSame('api', $result->context->credentialScope);
        self::assertSame('static-bearer', $result->context->authenticationMethod);
    }

    public function testStaticProviderRejectsUnknownToken(): void
    {
        $provider = new StaticCredentialProvider(['tok-1']);
        $result = $provider->resolve(new CredentialHandle('tok-evil', 'bearer', PHP_INT_MAX), 5_000);
        self::assertSame(AuthenticationStatus::FAILED, $result->status);
        self::assertNull($result->context);
    }

    public function testStaticProviderExpiresWhenNowPassesDeadline(): void
    {
        $provider = new StaticCredentialProvider(['tok-1'], expiresAtMs: 1_000);
        self::assertSame(
            AuthenticationStatus::AUTHENTICATED,
            $provider->resolve(new CredentialHandle('tok-1', 'bearer', PHP_INT_MAX), 500)->status,
        );
        self::assertSame(
            AuthenticationStatus::EXPIRED,
            $provider->resolve(new CredentialHandle('tok-1', 'bearer', PHP_INT_MAX), 2_000)->status,
        );
    }

    public function testStaticProviderRequiresValidTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StaticCredentialProvider([]);
    }

    // ------------------------------------------------------------------
    // AllowScopeAuthorizationPolicy
    // ------------------------------------------------------------------

    private function authzContext(string $principal, string $scope): SecurityContext
    {
        return new SecurityContext($principal, 'static-bearer', 'route', $scope, null);
    }

    public function testAllowScopeGrantsWhenScopeCovered(): void
    {
        $policy = new AllowScopeAuthorizationPolicy('payments.read');
        $result = $policy->authorize($this->authzContext('p1', 'payments.read,payments.write'), new SecurityRequest('op', 'res', 'read'));
        self::assertSame(SecurityVerdict::ALLOW, $result->verdict);
        self::assertSame('scope-granted', $result->policyCode);
    }

    public function testAllowScopeDeniesWhenScopeMissing(): void
    {
        $policy = new AllowScopeAuthorizationPolicy('admin');
        $result = $policy->authorize($this->authzContext('p1', 'payments.read'), new SecurityRequest('op', 'res', 'read'));
        self::assertSame(SecurityVerdict::DENY, $result->verdict);
        self::assertSame('scope-denied', $result->policyCode);
    }

    public function testAllowScopePermitsAnonymousOnlyWhenConfigured(): void
    {
        $deny = new AllowScopeAuthorizationPolicy('api');
        self::assertSame(
            SecurityVerdict::DENY,
            $deny->authorize($this->authzContext('ANONYMOUS', 'public'), new SecurityRequest('op', 'res', 'read'))->verdict,
        );
        $allow = new AllowScopeAuthorizationPolicy('api', allowAnonymous: true);
        self::assertSame(
            SecurityVerdict::ALLOW,
            $allow->authorize($this->authzContext('anonymous', 'public'), new SecurityRequest('op', 'res', 'read'))->verdict,
        );
    }

    public function testAllowScopeRejectsEmptyRequiredScope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AllowScopeAuthorizationPolicy('');
    }

    // ------------------------------------------------------------------
    // Value-object invariants
    // ------------------------------------------------------------------

    public function testAuthenticationResultInvariants(): void
    {
        $ctx = new SecurityContext('p', 'm', 'a', 's', null);
        try {
            new AuthenticationResult(AuthenticationStatus::AUTHENTICATED);
            self::fail('authenticated without context must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new AuthenticationResult(AuthenticationStatus::FAILED, $ctx);
            self::fail('failed with context must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(AuthenticationStatus::UNAUTHENTICATED, (new AuthenticationResult(AuthenticationStatus::UNAUTHENTICATED))->status);
    }

    public function testAuthorizationResultBounds(): void
    {
        try {
            new AuthorizationResult(SecurityVerdict::ALLOW, '');
            self::fail('empty policy code must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        new AuthorizationResult(SecurityVerdict::ALLOW, str_repeat('x', 129));
    }

    public function testSecurityAdmissionDecisionInvariants(): void
    {
        try {
            new SecurityAdmissionDecision(SecurityVerdict::ALLOW, SecurityFailure::REPLAY_REJECTED, false);
            self::fail('allow with failure must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new SecurityAdmissionDecision(SecurityVerdict::DENY, SecurityFailure::NONE, true);
            self::fail('deny with retry must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $d = new SecurityAdmissionDecision(SecurityVerdict::ALLOW, SecurityFailure::NONE, false);
        self::assertTrue($d->allows());
        self::assertFalse((new SecurityAdmissionDecision(SecurityVerdict::DENY, SecurityFailure::AUTHENTICATION_FAILED, false))->allows());
    }

    public function testSecurityContextBounds(): void
    {
        try {
            new SecurityContext('', 'm', 'a', 's', null);
            self::fail('empty principal must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new SecurityContext('p', str_repeat('m', 65), 'a', 's', null);
            self::fail('long auth method must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        new SecurityContext('p', 'm', 'a', 's', str_repeat('i', 129));
    }

    public function testCredentialHandleBounds(): void
    {
        try {
            new CredentialHandle(str_repeat('i', 129), 's', 0);
            self::fail('long handle id must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new CredentialHandle('id', str_repeat('s', 129), 0);
            self::fail('long scope must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        new CredentialHandle('id', 's', -1);
    }

    public function testSecurityRequestBoundChecks(): void
    {
        $cases = [
            static fn () => new SecurityRequest('', 'r', 'a'),
            static fn () => new SecurityRequest('o', '', 'a'),
            static fn () => new SecurityRequest('o', 'r', ''),
            static fn () => new SecurityRequest(str_repeat('o', 129), 'r', 'a'),
            static fn () => new SecurityRequest('o', 'r', 'a', str_repeat('r', 129)),
            static fn () => new SecurityRequest('o', 'r', 'a', null, self::manyAttributes(17)),
            static fn () => new SecurityRequest('o', 'r', 'a', null, ['authorization' => 'x']),
            static fn () => new SecurityRequest('o', 'r', 'a', null, ['secret_key' => 'x']),
            static fn () => new SecurityRequest('o', 'r', 'a', null, ['k' => new stdClass()]),
            static fn () => new SecurityRequest('o', 'r', 'a', null, ['k' => str_repeat('v', 257)]),
            static fn () => new SecurityRequest('o', 'r', 'a', null, ['k' => str_repeat('v', 2048)]),
        ];
        foreach ($cases as $case) {
            try {
                $case();
                self::fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('o', (new SecurityRequest('o', 'r', 'a', 'rid', ['k' => 'v']))->operationClass);
    }

    public function testReplayResultAllowsAcceptAndNotRequired(): void
    {
        $protector = new BoundedInMemoryReplayProtector(capacity: 1, windowMs: 100);
        self::assertTrue($protector->check(null, 1_000)->allows(), 'no id means not required');
        self::assertTrue($protector->check('r-1', 1_000)->allows(), 'first use accepted');
        self::assertFalse($protector->check('r-1', 1_001)->allows(), 'duplicate rejected');
        self::assertSame(ReplayDecision::REJECTED, $protector->check('', 1_000)->decision);
        self::assertSame(ReplayDecision::REJECTED, $protector->check(str_repeat('x', 129), 1_000)->decision);
    }

    public function testReplayProtectorPrunesStaleEntriesAndRejectsInvalidCtor(): void
    {
        $protector = new BoundedInMemoryReplayProtector(capacity: 10, windowMs: 100);
        self::assertTrue($protector->check('old', 1_000)->allows());
        self::assertTrue($protector->check('fresh', 5_000)->allows());
        // Re-checking 'old' after its window passed triggers pruning and a fresh accept.
        self::assertSame(ReplayDecision::ACCEPT, $protector->check('old', 5_000)->decision);
        self::assertSame(ReplayDecision::DUPLICATE, $protector->check('fresh', 5_000)->decision);
        try {
            new BoundedInMemoryReplayProtector(capacity: 0);
            self::fail('capacity 0 must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        new BoundedInMemoryReplayProtector(windowMs: 0);
    }

    public function testBoundaryDeniesUnavailableAndReplayUnavailable(): void
    {
        $boundary = new DefaultSecurityBoundary();
        $request = new SecurityRequest('o', 'r', 'a');
        $allow = new AllowScopeAuthorizationPolicy('api');

        $unavailable = $boundary->admit(
            new AuthenticationResult(AuthenticationStatus::UNAVAILABLE),
            $request,
            $allow,
            new BoundedInMemoryReplayProtector(),
            1_000,
        );
        self::assertSame(SecurityFailure::AUTHENTICATION_UNAVAILABLE, $unavailable->failure);

        // Full replay protector => REPLAY_UNAVAILABLE on the next distinct id.
        $ctx = new SecurityContext('p', 'm', 'a', 'api', null);
        $replay = new BoundedInMemoryReplayProtector(capacity: 1, windowMs: 100);
        $denied = $boundary->admit(
            new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, $ctx),
            new SecurityRequest('o', 'r', 'a', 'one'),
            $allow,
            $replay,
            1_000,
        );
        self::assertTrue($denied->allows());
        $second = $boundary->admit(
            new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, $ctx),
            new SecurityRequest('o', 'r', 'a', 'two'),
            $allow,
            $replay,
            1_001,
        );
        self::assertSame(SecurityFailure::REPLAY_UNAVAILABLE, $second->failure);
    }

    // ------------------------------------------------------------------
    // AuthenticationMiddleware (PSR-15)
    // ------------------------------------------------------------------

    private function middleware(): AuthenticationMiddleware
    {
        return new AuthenticationMiddleware(
            new StaticCredentialProvider(['tok-1'], principalId: 'svc', scope: 'api'),
            new AllowScopeAuthorizationPolicy('api'),
            new BoundedInMemoryReplayProtector(),
            new DefaultSecurityBoundary(),
        );
    }

    private function httpRequest(string $method, string $authorization = '', bool $withReplay = false): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/secure/endpoint');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturnMap([
            ['Authorization', $authorization],
            ['X-Replay-Id', $withReplay ? 'r-42' : ''],
        ]);
        $request->method('getAttribute')->willReturn(null);
        $request->method('withAttribute')->willReturnSelf();
        return $request;
    }

    public function testMiddlewareAllowsAuthenticatedScopedRequest(): void
    {
        $request = $this->httpRequest('POST', 'Bearer tok-1');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(
            $this->createMock(ResponseInterface::class),
        );
        $response = $this->middleware()->process($request, $handler);
        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testMiddlewareSafeRequestWithoutCredentialIsDeniedWhenProviderFails(): void
    {
        // GET (safe) proceeds to credential resolution with an anonymous handle;
        // the static provider does not authenticate it => boundary fails closed
        // with AUTHENTICATION_FAILED and the handler never runs (401).
        $request = $this->httpRequest('GET');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $response = $this->middleware()->process($request, $handler);
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('authentication_failed', (string) $response->getBody());
    }

    public function testMiddlewareRequiresAuthenticationForStateMutation(): void
    {
        $request = $this->httpRequest('POST');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $response = $this->middleware()->process($request, $handler);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testMiddlewareRejectsMalformedAuthorizationHeader(): void
    {
        // Non-Bearer scheme => no credential => 401 for POST.
        $request = $this->httpRequest('POST', 'Basic dXNlcjpwYXNz');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        self::assertSame(401, $this->middleware()->process($request, $handler)->getStatusCode());
    }

    // ------------------------------------------------------------------
    // ClientAddressResolver
    // ------------------------------------------------------------------

    private function addressRequest(string $remote, string $xff = ''): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $remote]);
        $request->method('getHeaderLine')->willReturn($xff);
        return $request;
    }

    public function testResolverTrustsDirectRemoteAddress(): void
    {
        self::assertSame('1.2.3.4', ClientAddressResolver::resolve($this->addressRequest('1.2.3.4')));
        self::assertSame('0.0.0.0', ClientAddressResolver::resolve($this->addressRequest('not-an-ip')));
        self::assertSame('0.0.0.0', ClientAddressResolver::resolve($this->addressRequest('')));
    }

    public function testResolverUsesForwardedForBehindTrustedProxy(): void
    {
        $request = $this->addressRequest('10.0.0.1', '203.0.113.9');
        self::assertSame('203.0.113.9', ClientAddressResolver::resolve($request, ['10.0.0.1']));
        // Untrusted proxy: XFF must be ignored.
        self::assertSame('10.0.0.1', ClientAddressResolver::resolve($this->addressRequest('10.0.0.1', '203.0.113.9')));
    }

    public function testResolverSkipsInvalidForwardedCandidates(): void
    {
        $request = $this->addressRequest('10.0.0.1', 'garbage, 198.51.100.7');
        self::assertSame('198.51.100.7', ClientAddressResolver::resolve($request, ['10.0.0.1']));
        $request2 = $this->addressRequest('10.0.0.1', 'garbage, also-bad');
        self::assertSame('10.0.0.1', ClientAddressResolver::resolve($request2, ['10.0.0.1']));
    }

    public function testResolverHonorsCidrTrustedProxies(): void
    {
        $request = $this->addressRequest('10.0.0.9', '203.0.113.10');
        self::assertSame('203.0.113.10', ClientAddressResolver::resolve($request, ['10.0.0.0/24']));
        $outside = $this->addressRequest('10.1.0.9', '203.0.113.10');
        self::assertSame('10.1.0.9', ClientAddressResolver::resolve($outside, ['10.0.0.0/24']));
        $v6 = $this->addressRequest('2001:db8::1', '203.0.113.11');
        self::assertSame('203.0.113.11', ClientAddressResolver::resolve($v6, ['2001:db8::/32']));
    }

    // ------------------------------------------------------------------
    // OriginPolicy
    // ------------------------------------------------------------------

    public function testOriginPolicyNormalization(): void
    {
        self::assertSame('https://example.com', OriginPolicy::normalizeOrigin('https://Example.COM'));
        self::assertSame('http://example.com', OriginPolicy::normalizeOrigin('http://example.com:80'));
        self::assertSame('https://example.com', OriginPolicy::normalizeOrigin('https://example.com:443'));
        self::assertSame('http://example.com:8080', OriginPolicy::normalizeOrigin('http://example.com:8080'));
        self::assertSame('null', OriginPolicy::normalizeOrigin('null'));
        self::assertSame('https://sub.example.org', OriginPolicy::normalizeOrigin('  https://SUB.Example.org  '));
    }

    public function testOriginPolicyRejectsMalformedOrigins(): void
    {
        $bad = [
            "https://example.com\r\nX-Evil: 1",
            'ftp://example.com',
            'https://user:pass@example.com',
            'https://example.com/path',
            'https://example.com?q=1',
            'https://example.com#frag',
            'https://exa mple.com',
            'https://example.com:0',
            'not-a-url',
        ];
        foreach ($bad as $origin) {
            try {
                OriginPolicy::normalizeOrigin($origin);
                self::fail("origin '{$origin}' must throw");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOriginPolicyAssertAllowed(): void
    {
        OriginPolicy::assertAllowed(null, ['https://example.com']);
        OriginPolicy::assertAllowed('', ['https://example.com']);
        OriginPolicy::assertAllowed('https://example.com', ['https://example.com']);
        try {
            OriginPolicy::assertAllowed('https://evil.com', ['https://example.com']);
            self::fail('disallowed origin must throw');
        } catch (InvalidConfigurationException) {
            $this->addToAssertionCount(1);
        }
    }

    // ------------------------------------------------------------------
    // CsrfTokenManager
    // ------------------------------------------------------------------

    public function testCsrfIssueAndValidateRoundTrip(): void
    {
        $manager = new CsrfTokenManager(secret: str_repeat('s', 32));
        $token = $manager->issue();
        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/', $token));
        self::assertTrue($manager->isValid($token));
        self::assertFalse($manager->isValid($token . 'x'));
        self::assertFalse($manager->isValid('tampered.' . substr($token, strrpos($token, '.') + 1)));
    }

    public function testCsrfRejectsMalformedTokens(): void
    {
        $manager = new CsrfTokenManager(secret: str_repeat('s', 32));
        foreach (['', 'abc', 'abc.def', 'no-dot', 'bad..' . str_repeat('f', 64), 'abc.' . str_repeat('g', 64)] as $token) {
            self::assertFalse($manager->isValid($token), "token '{$token}' must be invalid");
        }
    }

    public function testCsrfRejectsWrongSecret(): void
    {
        $issuer = new CsrfTokenManager(secret: str_repeat('a', 32));
        $verifier = new CsrfTokenManager(secret: str_repeat('b', 32));
        self::assertFalse($verifier->isValid($issuer->issue()));
    }

    public function testCsrfConstructorValidation(): void
    {
        try {
            new CsrfTokenManager(secret: 'short');
            self::fail('short secret must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        new CsrfTokenManager(secret: str_repeat('s', 32), tokenBytes: 8);
    }
}
