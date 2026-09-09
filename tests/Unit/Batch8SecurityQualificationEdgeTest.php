<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\Uri;
use Zef\Framework\Qualification\QualificationEvidence;
use Zef\Framework\Qualification\QualificationGate;
use Zef\Framework\Qualification\QualificationGateResult;
use Zef\Framework\Qualification\QualificationLedger;
use Zef\Framework\Qualification\QualificationStatus;
use Zef\Framework\Security\AuthenticationMiddleware;
use Zef\Framework\Security\ClientAddressResolver;
use Zef\Framework\Security\Distributed\AuthenticationResult;
use Zef\Framework\Security\Distributed\AuthenticationStatus;
use Zef\Framework\Security\Distributed\AuthorizationPolicyInterface;
use Zef\Framework\Security\Distributed\CredentialProviderInterface;
use Zef\Framework\Security\Distributed\ReplayDecision;
use Zef\Framework\Security\Distributed\ReplayProtectorInterface;
use Zef\Framework\Security\Distributed\ReplayResult;
use Zef\Framework\Security\Distributed\SecurityAdmissionDecision;
use Zef\Framework\Security\Distributed\SecurityBoundaryInterface;
use Zef\Framework\Security\Distributed\SecurityFailure;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\Distributed\SecurityVerdict;
use Zef\Framework\Security\SecurityContext;

/**
 * Batch 8 coverage: AuthenticationMiddleware 403-with-context branch,
 * ClientAddressResolver untrusted/invalid paths, QualificationLedger and
 * QualificationGateResult validation.
 *
 * Deterministic: fixed headers, injected credential provider / policy /
 * replay protector / boundary doubles; no wall-clock or random values.
 */
final class Batch8SecurityQualificationEdgeTest extends TestCase
{
    /** @param array<string,string> $headers */
    private function request(string $method, array $headers = [], ?SecurityContext $context = null): ServerRequestInterface
    {
        $attributes = $context !== null ? ['zef.security.context' => $context] : [];
        return new ServerRequest(
            $method,
            new Uri('https://example.com/api/orders'),
            ['REMOTE_ADDR' => '10.0.0.1'],
            [],
            [],
            [],
            null,
            $headers,
            Stream::fromString(''),
            '1.1',
            '',
            $attributes,
        );
    }

    private function handler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Zef\Framework\Http\Response(200, [], 'ok'));
        return $handler;
    }

    /** Build an AuthenticationMiddleware whose boundary denies with a fixed failure. */
    private function middleware(
        CredentialProviderInterface $provider,
        SecurityFailure $failure,
        bool $authenticated = true,
    ): AuthenticationMiddleware {
        $boundary = $this->createMock(SecurityBoundaryInterface::class);
        $boundary->method('admit')->willReturn(
            new SecurityAdmissionDecision(
                SecurityVerdict::DENY,
                $failure,
                false,
            ),
        );
        $authorization = $this->createMock(AuthorizationPolicyInterface::class);
        $replay = $this->createMock(ReplayProtectorInterface::class);
        $replay->method('check')->willReturn(new ReplayResult(ReplayDecision::NOT_REQUIRED));
        return new AuthenticationMiddleware($provider, $authorization, $replay, $boundary);
    }

    private function anonymousProvider(): CredentialProviderInterface
    {
        $provider = $this->createMock(CredentialProviderInterface::class);
        $provider->method('resolve')->willReturn(
            new AuthenticationResult(AuthenticationStatus::UNAUTHENTICATED),
        );
        return $provider;
    }

    private function authenticatedProvider(): CredentialProviderInterface
    {
        $provider = $this->createMock(CredentialProviderInterface::class);
        $provider->method('resolve')->willReturn(
            new AuthenticationResult(
                AuthenticationStatus::AUTHENTICATED,
                new Zef\Framework\Security\Distributed\SecurityContext(
                    'principal-1',
                    'bearer',
                    'scope-set',
                    'orders:read',
                    null,
                ),
            ),
        );
        return $provider;
    }

    // ----------------------------------------- AuthenticationMiddleware paths

    public function testSafeMethodWithoutCredentialProceedsToAdmission(): void
    {
        $provider = $this->createMock(CredentialProviderInterface::class);
        $provider->method('resolve')->willReturn(
            new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, $this->distributedContext()),
        );
        $boundary = $this->createMock(SecurityBoundaryInterface::class);
        $boundary->method('admit')->willReturn(
            new SecurityAdmissionDecision(
                SecurityVerdict::ALLOW,
                SecurityFailure::NONE,
                true,
            ),
        );
        $authorization = $this->createMock(AuthorizationPolicyInterface::class);
        $replay = $this->createMock(ReplayProtectorInterface::class);
        $middleware = new AuthenticationMiddleware($provider, $authorization, $replay, $boundary);

        $response = $middleware->process($this->request('GET'), $this->handler());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMalformedBearerYieldsAnonymousThen401OnUnsafeMethod(): void
    {
        // Bearer scheme with empty token -> extractCredential() returns null.
        $middleware = $this->middleware($this->anonymousProvider(), SecurityFailure::AUTHENTICATION_FAILED);
        $response = $middleware->process(
            $this->request('POST', ['Authorization' => 'Bearer   ']),
            $this->handler(),
        );
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertNotSame('', $response->getHeaderLine('X-Request-ID'));
    }

    public function testOversizedBearerTokenYieldsAnonymous(): void
    {
        $provider = $this->createMock(CredentialProviderInterface::class);
        // If the oversized token were accepted, resolve() would be called.
        $provider->expects($this->never())->method('resolve');
        $boundary = $this->createMock(SecurityBoundaryInterface::class);
        $authorization = $this->createMock(AuthorizationPolicyInterface::class);
        $replay = $this->createMock(ReplayProtectorInterface::class);
        $middleware = new AuthenticationMiddleware($provider, $authorization, $replay, $boundary);

        $response = $middleware->process(
            $this->request('DELETE', ['Authorization' => 'Bearer ' . str_repeat('a', 600)]),
            $this->handler(),
        );
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testDeniedAdmissionWithAuthenticatedContextReturns403(): void
    {
        $middleware = $this->middleware(
            $this->authenticatedProvider(),
            SecurityFailure::AUTHORIZATION_DENIED,
            true,
        );
        $response = $middleware->process(
            $this->request('POST', ['Authorization' => 'Bearer valid-token']),
            $this->handler(),
        );
        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertSame('authorization_denied', $body['error']);
        $this->assertSame(403, $body['status']);
        $this->assertSame($response->getHeaderLine('X-Request-ID'), $body['correlation_id']);
    }

    public function testAllowedAdmissionStoresPrincipalAttribute(): void
    {
        $provider = $this->authenticatedProvider();
        $boundary = $this->createMock(SecurityBoundaryInterface::class);
        $boundary->method('admit')->willReturn(
            new SecurityAdmissionDecision(SecurityVerdict::ALLOW, SecurityFailure::NONE, true),
        );
        $authorization = $this->createMock(AuthorizationPolicyInterface::class);
        $replay = $this->createMock(ReplayProtectorInterface::class);
        $middleware = new AuthenticationMiddleware($provider, $authorization, $replay, $boundary);

        $seen = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
                $seen = $request->getAttribute('zef.security.principal');
                return new Zef\Framework\Http\Response(200, [], 'ok');
            },
        );
        $middleware->process($this->request('POST', ['Authorization' => 'Bearer token-1']), $handler);
        $this->assertSame('principal-1', $seen);
    }

    public function testDenyWithExistingSecurityContextReusesRequestId(): void
    {
        $securityContext = new SecurityContext('fixed-req-123', '10.0.0.1', null, true);
        $request = $this->request('PUT', ['Authorization' => 'Bearer t'], $securityContext);
        $middleware = $this->middleware($this->authenticatedProvider(), SecurityFailure::AUTHORIZATION_DENIED);
        $response = $middleware->process($request, $this->handler());
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('fixed-req-123', $response->getHeaderLine('X-Request-ID'));
    }

    public function testOverlongOperationClassRejectedByBoundaryContract(): void
    {
        $provider = $this->authenticatedProvider();
        // operationClass = method + ' ' + path must stay <= 128 bytes; a path
        // longer than ~123 bytes trips the SecurityRequest bound, so the
        // middleware surfaces it as InvalidArgumentException (defensive).
        $uri = new Uri('https://example.com/' . str_repeat('a', 150));
        $request = new ServerRequest('POST', $uri, [], [], [], [], null, ['Authorization' => 'Bearer t']);

        $boundary = $this->createMock(SecurityBoundaryInterface::class);
        $authorization = $this->createMock(AuthorizationPolicyInterface::class);
        $replay = $this->createMock(ReplayProtectorInterface::class);
        $middleware = new AuthenticationMiddleware($provider, $authorization, $replay, $boundary);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('operationClass exceeds its bound.');
        $middleware->process($request, $this->handler());
    }

    // ------------------------------------------- ClientAddressResolver paths

    public function testUntrustedProxyIgnoresForwardedFor(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '203.0.113.9']);
        $request->method('getHeaderLine')->willReturn('203.0.113.9');
        $this->assertSame('203.0.113.9', ClientAddressResolver::resolve($request, ['10.0.0.0/8']));
    }

    public function testTrustedProxyPicksFirstValidForwardedCandidate(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '10.0.0.5']);
        $request->method('getHeaderLine')->willReturn('not-an-ip, 198.51.100.7, 203.0.113.1');
        $this->assertSame('198.51.100.7', ClientAddressResolver::resolve($request, ['10.0.0.0/8']));
    }

    public function testTrustedProxyNoValidCandidateFallsBackToRemote(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '10.0.0.5']);
        $request->method('getHeaderLine')->willReturn('garbage,,values');
        $this->assertSame('10.0.0.5', ClientAddressResolver::resolve($request, ['10.0.0.5']));
    }

    public function testMissingRemoteAddrReturnsSentinel(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn([]);
        $request->method('getHeaderLine')->willReturn('');
        $this->assertSame('0.0.0.0', ClientAddressResolver::resolve($request));
    }

    public function testRemoteMatchingExactIpTrustedEntry(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '192.168.1.1']);
        $request->method('getHeaderLine')->willReturn('172.16.0.9');
        $this->assertSame('172.16.0.9', ClientAddressResolver::resolve($request, ['192.168.1.1']));
    }

    public function testIpv6CidrTrustedProxy(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '2001:db8::5']);
        $request->method('getHeaderLine')->willReturn('2001:db8:ffff::1');
        $this->assertSame(
            '2001:db8:ffff::1',
            ClientAddressResolver::resolve($request, ['2001:db8::/32']),
        );
    }

    // ----------------------------------------------------- QualificationLedger

    private function gate(string $id = 'g1', bool $mandatory = true): QualificationGate
    {
        return new QualificationGate($id, 'Gate ' . $id, $mandatory);
    }

    public function testRegisterDuplicateGateThrows(): void
    {
        $ledger = new QualificationLedger();
        $ledger->register($this->gate());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already registered');
        $ledger->register($this->gate());
    }

    public function testRecordUnknownGateThrows(): void
    {
        $ledger = new QualificationLedger();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown qualification gate');
        $ledger->record('nope', QualificationStatus::PASS, [new QualificationEvidence('a', str_repeat('0', 64), 'ci', 's')]);
    }

    public function testMandatoryPassWithoutEvidenceRejected(): void
    {
        $ledger = new QualificationLedger();
        $ledger->register($this->gate('gate-a', true));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires evidence');
        $ledger->record('gate-a', QualificationStatus::PASS);
    }

    public function testEmptyLedgerStatusIsNotStartedAndCannotPromote(): void
    {
        $ledger = new QualificationLedger();
        $this->assertSame(QualificationStatus::NOT_STARTED, $ledger->status());
        $this->assertFalse($ledger->canPromote());
    }

    public function testStatusShortCircuitsOnFail(): void
    {
        $ledger = new QualificationLedger();
        $ledger->register($this->gate('a'));
        $ledger->register($this->gate('b'));
        $ledger->record('a', QualificationStatus::FAIL);
        $ledger->record('b', QualificationStatus::NOT_STARTED);
        $this->assertSame(QualificationStatus::FAIL, $ledger->status());
    }

    public function testStatusInProgressWhenAnyNotStartedOrInProgress(): void
    {
        $ledger = new QualificationLedger();
        $ledger->register($this->gate('a'));
        $ledger->register($this->gate('b'));
        $ledger->record('a', QualificationStatus::IN_PROGRESS);
        $this->assertSame(QualificationStatus::IN_PROGRESS, $ledger->status());
    }

    public function testStatusWaivedForMandatoryWaivedGate(): void
    {
        $ledger = new QualificationLedger();
        $ledger->register($this->gate('a', true));
        $ledger->record('a', QualificationStatus::WAIVED);
        $this->assertSame(QualificationStatus::WAIVED, $ledger->status());
        $this->assertFalse($ledger->canPromote());
    }

    public function testAllPassWithEvidencePromotes(): void
    {
        $ledger = new QualificationLedger();
        $ledger->register($this->gate('a'));
        $evidence = [new QualificationEvidence('build', str_repeat('a', 64), 'ci', 'green')];
        $ledger->record('a', QualificationStatus::PASS, $evidence, 'all green');
        $this->assertSame(QualificationStatus::PASS, $ledger->status());
        $this->assertTrue($ledger->canPromote());
        $results = $ledger->results();
        $this->assertCount(1, $results);
        $this->assertSame('all green', $results[0]->note);
    }

    public function testOptionalGateFailureStillPromotesWhenMandatoryPassed(): void
    {
        $ledger = new QualificationLedger();
        $ledger->register($this->gate('mandatory', true));
        $ledger->register($this->gate('optional', false));
        $evidence = [new QualificationEvidence('b', str_repeat('b', 64), 'ci', 'green')];
        $ledger->record('mandatory', QualificationStatus::PASS, $evidence);
        $ledger->record('optional', QualificationStatus::FAIL);
        $this->assertTrue($ledger->canPromote());
    }

    public function testQualificationGateResultRejectsNonEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('QualificationEvidence objects');
        new QualificationGateResult($this->gate(), QualificationStatus::PASS, ['not-evidence']);
    }

    public function testQualificationGateResultRejectsOverlongNote(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('note is too long');
        new QualificationGateResult($this->gate(), QualificationStatus::FAIL, [], str_repeat('n', 600));
    }

    public function testQualificationEvidenceValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QualificationEvidence('', str_repeat('0', 64), 'ci', 's');
    }

    // ------------------------------------------------------------- helpers

    private function distributedContext(): Zef\Framework\Security\Distributed\SecurityContext
    {
        return new Zef\Framework\Security\Distributed\SecurityContext('p1', 'bearer', 'scope-set', 'scope:x', null);
    }
}
