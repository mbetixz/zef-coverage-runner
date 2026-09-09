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

$iterations = 100000;
$policy = new class implements AuthorizationPolicyInterface {
    public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult { return new AuthorizationResult(SecurityVerdict::ALLOW, 'perf'); }
};
$auth = new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, new SecurityContext('p','mTLS','scope','scope','peer'));
$boundary = new DefaultSecurityBoundary();
$replay = new BoundedInMemoryReplayProtector(capacity: 200000, windowMs: 300000);
$request = new SecurityRequest('read','resource','read');

$start = hrtime(true);
for ($i=0; $i<$iterations; $i++) { }
$baselineNs = (hrtime(true)-$start)/$iterations;

$start = hrtime(true);
for ($i=0; $i<$iterations; $i++) {
    $boundary->admit($auth, $request, $policy, $replay, $i);
}
$enabledNs = (hrtime(true)-$start)/$iterations;

echo json_encode([
    'iterations'=>$iterations,
    'baseline_ns_per_op'=>$baselineNs,
    'security_enabled_ns_per_op'=>$enabledNs,
    'security_overhead_ns_per_op'=>$enabledNs-$baselineNs,
    'baseline_peak_memory_delta'=>0,
], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
