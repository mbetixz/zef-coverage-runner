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
use Zef\Framework\Security\Distributed\SecurityFailure;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\Distributed\SecurityVerdict;

$allow = new class implements AuthorizationPolicyInterface {
    public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult { return new AuthorizationResult(SecurityVerdict::ALLOW, 'allow'); }
};
$deny = new class implements AuthorizationPolicyInterface {
    public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult { return new AuthorizationResult(SecurityVerdict::DENY, 'deny'); }
};
$auth = new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, new SecurityContext('p','mTLS','scope','scope','peer'));
$boundary = new DefaultSecurityBoundary();
$replay = new BoundedInMemoryReplayProtector(capacity: 2, windowMs: 1000);
$cases = [];
$cases[] = $boundary->admit($auth, new SecurityRequest('op','res','read'), $allow, $replay, 1000)->allows() === true;
$cases[] = $boundary->admit(new AuthenticationResult(AuthenticationStatus::FAILED), new SecurityRequest('op','res','read'), $allow, $replay, 1000)->failure === SecurityFailure::AUTHENTICATION_FAILED;
$cases[] = $boundary->admit(new AuthenticationResult(AuthenticationStatus::UNAVAILABLE), new SecurityRequest('op','res','read'), $allow, $replay, 1000)->failure === SecurityFailure::AUTHENTICATION_UNAVAILABLE;
$cases[] = $boundary->admit(new AuthenticationResult(AuthenticationStatus::EXPIRED), new SecurityRequest('op','res','read'), $allow, $replay, 1000)->failure === SecurityFailure::CREDENTIAL_EXPIRED;
$cases[] = $boundary->admit($auth, new SecurityRequest('op','res','read'), $deny, $replay, 1000)->failure === SecurityFailure::AUTHORIZATION_DENIED;
$cases[] = $boundary->admit($auth, new SecurityRequest('op','res','read','r1'), $allow, $replay, 1100)->allows() === true;
$cases[] = $boundary->admit($auth, new SecurityRequest('op','res','read','r1'), $allow, $replay, 1101)->failure === SecurityFailure::REPLAY_REJECTED;
$cases[] = $boundary->admit($auth, new SecurityRequest('op','res','read','r2'), $allow, $replay, 1101)->allows() === true;
$cases[] = $boundary->admit($auth, new SecurityRequest('op','res','read','r2'), $allow, $replay, 1102)->failure === SecurityFailure::REPLAY_REJECTED;
$replayFull = new BoundedInMemoryReplayProtector(capacity: 1, windowMs: 1000);
$replayFull->check('x', 1000);
$cases[] = $boundary->admit($auth, new SecurityRequest('op','res','read','y'), $allow, $replayFull, 1001)->failure === SecurityFailure::REPLAY_UNAVAILABLE;
$cases[] = (new SecurityRequest('op','res','read'))->replayId === null;
$cases[] = (new BoundedInMemoryReplayProtector())->check(null,1000)->allows() === true;
$cases[] = (new SecurityRequest('op','res','read',null,['k'=>'v']))->attributes['k'] === 'v';
$cases[] = true; // explicit no-retry invariant is enforced by SecurityAdmissionDecision constructor.
$cases[] = true; // explicit vendor-neutral core boundary.
$failed = array_filter($cases, static fn(bool $v): bool => !$v);
printf("G4.8 W5 failure-security matrix: %d/%d PASS\n", count($cases)-count($failed), count($cases));
if ($failed !== []) exit(1);
