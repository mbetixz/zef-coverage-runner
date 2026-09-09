<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Security\Distributed\AuthenticationResult;
use Zef\Framework\Security\Distributed\AuthenticationStatus;
use Zef\Framework\Security\Distributed\AuthorizationPolicyInterface;
use Zef\Framework\Security\Distributed\AuthorizationResult;
use Zef\Framework\Security\Distributed\BoundedInMemoryReplayProtector;
use Zef\Framework\Security\Distributed\DefaultSecurityBoundary;
use Zef\Framework\Security\Distributed\ReplayDecision;
use Zef\Framework\Security\Distributed\ReplayProtectorInterface;
use Zef\Framework\Security\Distributed\SecurityContext;
use Zef\Framework\Security\Distributed\SecurityFailure;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\Distributed\SecurityVerdict;

final class G4_8DistributedSecurityTest extends TestCase
{
    private function authenticated(): AuthenticationResult
    {
        return new AuthenticationResult(
            AuthenticationStatus::AUTHENTICATED,
            new SecurityContext('principal-A', 'mTLS', 'payments.read', 'payments', 'peer-A'),
        );
    }

    public function testAuthorizedFreshRequestIsAllowed(): void
    {
        $policy = new class implements AuthorizationPolicyInterface {
            #[\Override]
            public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
            { return new AuthorizationResult(SecurityVerdict::ALLOW, 'policy-v1'); }
        };
        $result = (new DefaultSecurityBoundary())->admit(
            $this->authenticated(), new SecurityRequest('payment.read', 'payment', 'read', 'r-1'), $policy,
            new BoundedInMemoryReplayProtector(), 1000,
        );
        self::assertTrue($result->allows());
        self::assertSame(SecurityFailure::NONE, $result->failure);
    }

    public function testAuthenticationFailureFailsClosed(): void
    {
        $policy = new class implements AuthorizationPolicyInterface {
            #[\Override]
            public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
            { throw new LogicException('must not execute'); }
        };
        $result = (new DefaultSecurityBoundary())->admit(
            new AuthenticationResult(AuthenticationStatus::FAILED), new SecurityRequest('payment.read', 'payment', 'read'), $policy,
            new BoundedInMemoryReplayProtector(), 1000,
        );
        self::assertSame(SecurityVerdict::DENY, $result->verdict);
        self::assertSame(SecurityFailure::AUTHENTICATION_FAILED, $result->failure);
        self::assertFalse($result->retryAllowed);
    }

    public function testExpiredCredentialFailsClosed(): void
    {
        $policy = new class implements AuthorizationPolicyInterface {
            #[\Override]
            public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
            { throw new LogicException('must not execute'); }
        };
        $result = (new DefaultSecurityBoundary())->admit(
            new AuthenticationResult(AuthenticationStatus::EXPIRED), new SecurityRequest('payment.write', 'payment', 'write'), $policy,
            new BoundedInMemoryReplayProtector(), 1000,
        );
        self::assertSame(SecurityFailure::CREDENTIAL_EXPIRED, $result->failure);
    }

    public function testAuthorizationDenialFailsClosed(): void
    {
        $policy = new class implements AuthorizationPolicyInterface {
            #[\Override]
            public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
            { return new AuthorizationResult(SecurityVerdict::DENY, 'policy-deny'); }
        };
        $result = (new DefaultSecurityBoundary())->admit(
            $this->authenticated(), new SecurityRequest('payment.write', 'payment', 'write'), $policy,
            new BoundedInMemoryReplayProtector(), 1000,
        );
        self::assertSame(SecurityFailure::AUTHORIZATION_DENIED, $result->failure);
        self::assertFalse($result->retryAllowed);
    }

    public function testReplayDuplicateIsRejectedAndNotRetried(): void
    {
        $policy = new class implements AuthorizationPolicyInterface {
            #[\Override]
            public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
            { return new AuthorizationResult(SecurityVerdict::ALLOW, 'policy-v1'); }
        };
        $replay = new BoundedInMemoryReplayProtector(capacity: 4, windowMs: 1000);
        $request = new SecurityRequest('payment.write', 'payment', 'write', 'replay-1');
        $boundary = new DefaultSecurityBoundary();
        self::assertTrue($boundary->admit($this->authenticated(), $request, $policy, $replay, 1000)->allows());
        $result = $boundary->admit($this->authenticated(), $request, $policy, $replay, 1001);
        self::assertSame(ReplayDecision::DUPLICATE, $replay->check('replay-1', 1001)->decision);
        self::assertSame(SecurityFailure::REPLAY_REJECTED, $result->failure);
        self::assertFalse($result->retryAllowed);
    }

    public function testReplayCapacityFailsClosed(): void
    {
        $replay = new BoundedInMemoryReplayProtector(capacity: 1, windowMs: 1000);
        self::assertSame(ReplayDecision::ACCEPT, $replay->check('a', 1000)->decision);
        self::assertSame(ReplayDecision::UNAVAILABLE, $replay->check('b', 1001)->decision);
    }

    public function testSecurityMetadataIsBoundedAndObjectsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecurityRequest('op', 'resource', 'action', null, ['bad' => new stdClass()]);
    }

    public function testGenericAttributesRejectSensitiveCredentialKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecurityRequest('op', 'resource', 'action', null, ['authorization_token' => 'secret']);
    }

    public function testNoReplayIdentifierMeansReplayProtectionNotRequired(): void
    {
        $replay = new BoundedInMemoryReplayProtector();
        self::assertSame(ReplayDecision::NOT_REQUIRED, $replay->check(null, 1000)->decision);
    }
}
