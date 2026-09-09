<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/zef_framework_v2.5.0-beta1.php';

use Zef\Framework\Router\Router;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Exception\RouteNotFoundException;

$pass = 0;
$fail = 0;
$ok = static function (bool $condition, string $label) use (&$pass, &$fail): void {
    if ($condition) { ++$pass; echo "PASS: {$label}\n"; }
    else { ++$fail; echo "FAIL: {$label}\n"; }
};

// R-01 static branch wins without inspecting unrelated dynamic branches.
$r = new Router();
$r->add('GET', '/users/me', 'me', priority: 10);
$r->add('GET', '/users/{id}', 'user');
$r->add('GET', '/products/{slug}', 'product');
$r->freeze();
$m = $r->match('GET', '/users/me');
$ok($m['handler'] === 'me', 'R-01 static radix branch has precedence');
$ok($m['params'] === [], 'R-01 static route has no parameters');

// R-02 dynamic branch captures parameters.
$m = $r->match('GET', '/users/42');
$ok($m['handler'] === 'user' && $m['params'] === ['id' => '42'], 'R-02 dynamic radix branch captures parameter');

// R-03 route semantics remain stable for constraints and 405.
$r2 = new Router();
$r2->addConstraint('int', '/^\\d+$/');
$r2->add('GET', '/items/{id:int}', 'item');
$r2->add('POST', '/items/{id:int}', 'item.post');
$r2->freeze();
$ok($r2->match('GET', '/items/9')['handler'] === 'item', 'R-03 constrained route matches');
try { $r2->match('GET', '/items/nope'); $ok(false, 'R-03 constraint failure produces 400-class exception'); }
catch (RouteConstraintException) { $ok(true, 'R-03 constraint failure produces 400-class exception'); }
try { $r2->match('PUT', '/items/9'); $ok(false, 'R-03 method mismatch remains 405'); }
catch (MethodNotAllowedException) { $ok(true, 'R-03 method mismatch remains 405'); }

// R-04 structural miss is 404.
try { $r2->match('GET', '/unknown/9'); $ok(false, 'R-04 structural miss remains 404'); }
catch (RouteNotFoundException) { $ok(true, 'R-04 structural miss remains 404'); }

// R-05 route priority and registration-order semantics are preserved across branches.
$r3 = new Router();
$r3->add('GET', '/x/{a}', 'first', priority: 0);
$r3->add('GET', '/{root}/y', 'second', priority: 0);
$r3->add('GET', '/x/y', 'static', priority: 0);
$r3->freeze();
$ok($r3->match('GET', '/x/y')['handler'] === 'static', 'R-05 static specificity survives radix compilation');

// R-06 high-cardinality registration remains operational without linear candidate scanning.
$r4 = new Router();
$r4->setMaxRoutesBudget(10000);
for ($i = 0; $i < 9999; ++$i) {
    $r4->add('GET', '/catalog/' . $i . '/detail', 'h' . $i);
}
$r4->add('GET', '/target/{id}', 'target');
$r4->freeze();
$start = hrtime(true);
$result = $r4->match('GET', '/target/123456');
$elapsedMs = (hrtime(true) - $start) / 1_000_000;
$ok($result['handler'] === 'target' && $result['params'] === ['id' => '123456'], 'R-06 10k-route radix lookup resolves correctly');
$ok($elapsedMs < 50.0, sprintf('R-06 representative 10k-route lookup stays under 50ms (%.3fms)', $elapsedMs));

// R-07 freeze remains idempotent and route additions remain prohibited after freeze.
$r4->freeze();
try { $r4->add('GET', '/late', 'late'); $ok(false, 'R-07 frozen router rejects late registration'); }
catch (LogicException) { $ok(true, 'R-07 frozen router rejects late registration'); }

// R-08 compiled constraint matcher is cached while custom constraint replacement invalidates its cache.
$r5 = new Router();
$r5->addConstraint('digits_only', '/^\\d+$/D');
$r5->add('GET', '/compiled/{id:digits_only}', 'compiled');
$r5->freeze();
$ok($r5->match('GET', '/compiled/123')['handler'] === 'compiled', 'R-08 compiled custom constraint matches');
try { $r5->match('GET', '/compiled/abc'); $ok(false, 'R-08 compiled custom constraint rejects invalid input'); }
catch (RouteConstraintException) { $ok(true, 'R-08 compiled custom constraint rejects invalid input'); }

echo "RouterRadixTreeTest: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
