<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Zef\Framework\Security\Distributed\AuthenticationResult;
use Zef\Framework\Security\Distributed\AuthenticationStatus;
use Zef\Framework\Security\Distributed\AuthorizationPolicyInterface;
use Zef\Framework\Security\Distributed\AuthorizationResult;
use Zef\Framework\Security\Distributed\BoundedInMemoryReplayProtector;
use Zef\Framework\Security\Distributed\DefaultSecurityBoundary;
use Zef\Framework\Security\Distributed\SecurityContext;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\Distributed\SecurityVerdict;

$policy = new class implements AuthorizationPolicyInterface {
    public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
    { return new AuthorizationResult(SecurityVerdict::ALLOW, 'smoke'); }
};
$auth = new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, new SecurityContext('p1','mTLS','read','read','peer1'));
$result = (new DefaultSecurityBoundary())->admit($auth, new SecurityRequest('read','resource','read','smoke-1'), $policy, new BoundedInMemoryReplayProtector(), 1000);
if (!$result->allows()) { fwrite(STDERR, "unexpected security deny\n"); exit(1); }

$replay = new BoundedInMemoryReplayProtector(capacity: 1, windowMs: 1000);
if (!$replay->check('a',1000)->allows() || $replay->check('a',1001)->allows()) { fwrite(STDERR, "replay semantics failure\n"); exit(1); }

echo "G4.8 W5 native security contract smoke PASS\n";
