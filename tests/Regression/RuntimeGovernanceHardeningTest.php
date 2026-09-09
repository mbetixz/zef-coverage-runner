<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/zef_framework_v2.5.0-beta1.php';

use Zef\App\Bootstrap;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Container\FailFastInitializationGuard;
use Zef\Framework\Exception\ConcurrentServiceInitializationException;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\PayloadTooLargeException;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\LimitedInputStream;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Router\Router;
use Zef\Framework\Validation\RouteConstraintValidator;
use Zef\Framework\Foundation\ZefVersion;

$fail = 0; $pass = 0;
$ok = static function(bool $c, string $label) use (&$fail,&$pass): void {
    if ($c) { ++$pass; echo "PASS: $label\n"; }
    else { ++$fail; echo "FAIL: $label\n"; }
};
$throws = static function(string $class, callable $fn, string $label) use ($ok): void {
    try { $fn(); $ok(false, $label . ' (no exception)'); }
    catch (Throwable $e) { $ok($e instanceof $class, $label . ' -> ' . get_class($e)); }
};

// H-01 Router immutable after application boot.
$app = Bootstrap::createApp(false);
$app->boot();
$throws(LogicException::class, fn() => $app->getRouter()->add('GET','/late-route','missing'), 'H-01 router rejects mutation after boot');

// H-02 bounded request body stream.
$policy = new RequestBodyPolicy(8);
$throws(PayloadTooLargeException::class, fn() => (new LimitedInputStream(Stream::fromString('123456789'), $policy))->getContents(), 'H-02 body hard limit enforced');
$under = new LimitedInputStream(Stream::fromString('12345678'), $policy);
$ok($under->getContents() === '12345678', 'H-02 body at exact limit accepted');
$throws(InvalidArgumentException::class, fn() => new RequestBodyPolicy(0), 'H-02 invalid body policy rejected');

// H-03 fail-fast overlapping singleton initialization guard.
$guard = new FailFastInitializationGuard();
$throws(ConcurrentServiceInitializationException::class, fn() => $guard->synchronized('x', fn() => $guard->synchronized('x', fn() => true)), 'H-03 concurrent singleton initialization is fail-fast');
$fiberA = new Fiber(fn() => $guard->synchronized('fiber-key', function(){ Fiber::suspend('initializing'); return new stdClass(); }));
$ok($fiberA->start() === 'initializing', 'H-03 Fiber A enters singleton initialization');
$fiberB = new Fiber(fn() => $guard->synchronized('fiber-key', fn() => new stdClass()));
try { $fiberB->start(); $ok(false, 'H-03 Fiber B is blocked from duplicate initialization'); } catch (ConcurrentServiceInitializationException $e) { $ok(true, 'H-03 Fiber B is blocked from duplicate initialization'); }
$fiberA->resume();

// H-04 no browser selftest endpoint in production monolith.
$source = file_get_contents($root . '/zef_framework_v2.5.0-beta1.php');
if($source===false){ $source=''; }
$ok(!str_contains($source, "\$_GET['selftest']"), 'H-04 browser self-test endpoint removed');
$ok(str_contains($source, "--self-test"), 'H-04 CLI self-test remains available');

// M-01 constructor and mutation share method validation.
$throws(InvalidArgumentException::class, fn() => new ServerRequest("BAD METHOD\r\n", new Uri('http://localhost/',['localhost'])), 'M-01 ServerRequest constructor rejects invalid method');
$throws(InvalidArgumentException::class, fn() => (new ServerRequest('GET', new Uri('http://localhost/',['localhost'])))->withMethod("BAD METHOD\r\n"), 'M-01 withMethod rejects invalid method');

// M-02 shared=false and lazy=true have observable semantics.
$c = new Container();
$count = 0;
$c->registerDefinition(new ServiceDefinition('nonshared', function() use (&$count){ ++$count; return new stdClass(); }, [], 'test', ServiceLifetime::SINGLETON, false, false));
$c->registerDefinition(new ServiceDefinition('lazy', function(){ return new stdClass(); }, [], 'test', ServiceLifetime::SINGLETON, true, true));
$c->validateAndFreeze();
$a=$c->get('nonshared'); $b=$c->get('nonshared');
$ok($a !== $b && $count === 2, 'M-02 shared=false creates distinct singleton-lifetime instances');
$before = $c->has('lazy'); $ok($before && $c->getRegistry()->definitions()['lazy']->lazy === true, 'M-02 lazy metadata preserved');
$warm = new Container(); $warmCount=0; $warm->registerDefinition(new ServiceDefinition('lazy2', function() use (&$warmCount){++$warmCount;return new stdClass();}, [], 'test', ServiceLifetime::SINGLETON, true, true)); $warm->validateAndFreeze(); $warm->warmSingletons(); $ok($warmCount===0, 'M-02 lazy service is not eagerly warmed');

// M-03 cache mutation moved behind resolver-owned WeakMap; deprecated surface remains for compatibility.
$scope = $c->createRequestScope();
$method = new ReflectionMethod($scope, 'setInstance');
$ok($method->isPublic(), 'M-03 compatibility API remains public but deprecated');
$doc = $method->getDocComment() ?: '';
$ok(str_contains($doc, '@deprecated'), 'M-03 cache mutation API is explicitly deprecated');
$scope->close();

// M-04 conservative regex policy.
$validator = new RouteConstraintValidator();
$throws(InvalidConfigurationException::class, fn() => $validator->addCustom('long', '/' . str_repeat('a', 2049) . '/'), 'M-04 oversized regex rejected');
$throws(InvalidConfigurationException::class, fn() => $validator->addCustom('redos', '/(a+)+$/'), 'M-04 nested quantified regex rejected');

// M-05 package metadata expectation (the release package supplies composer.json when published).
$composerPath = $root . '/composer.json';
if (is_file($composerPath)) {
    $composer = json_decode((string)file_get_contents($composerPath), true);
    if(!is_array($composer)){ $composer=[]; }
    /** @var array<string,mixed> $composer */
    $req = $composer['require'] ?? [];
    $req = is_array($req) ? $req : [];
    /** @var array<string,mixed> $req */
    $ok(isset($req['psr/log']), 'M-05 composer declares psr/log');
} else {
    echo "INFO: M-05 composer.json is external packaging metadata in this test artifact.\n";
}

// M-06 Reflection ABI sanity for public method signature.
$rm = new ReflectionMethod(ServiceDefinition::class, 'fromArray');
$ok($rm->isPublic() && $rm->getNumberOfParameters()===3, 'M-06 public surface reflection captures ServiceDefinition::fromArray');
$rt = $rm->getReturnType();
$ok($rt !== null && (string)$rt === 'self', 'M-06 return type is captured semantically');

// M-07 runtime banner version authority.
ob_start(); (new Zef\Test\CliRunner())->run(false); $out = ob_get_clean();
if($out===false){ $out=''; }
$ok(str_contains($out, 'ZEF Framework v' . ZefVersion::VERSION . ' — SELF TEST'), 'M-07 self-test banner reads version authority');

// M-08 cross-module policy plumbing remains configured and nonnegative.
$policyValue = new \Zef\Framework\Policy\ArchitecturePolicy(maxCrossModuleRefs:3,maxServiceRegistrations:5,maxRouteRegistrations:6,maxResolutionDepth:7);
$ok($policyValue->maxCrossModuleRefs===3 && $policyValue->maxServiceRegistrations===5 && $policyValue->maxRouteRegistrations===6 && $policyValue->maxResolutionDepth===7, 'M-08 typed architecture policy preserves configured limits');
$throws(InvalidArgumentException::class, fn()=>new \Zef\Framework\Policy\ArchitecturePolicy(maxResolutionDepth:0), 'M-08 invalid architecture policy rejected');

echo "\nTOTAL PASS: $pass  FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
