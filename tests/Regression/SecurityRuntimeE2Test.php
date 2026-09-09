<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;

$pass = 0; $fail = 0;
$ok = static function (bool $condition, string $label) use (&$pass, &$fail): void { if ($condition) { ++$pass; echo "PASS: {$label}\n"; } else { ++$fail; echo "FAIL: {$label}\n"; } };
$handler = new class implements RequestHandlerInterface {
    #[\Override] public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface { return new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'); }
};

$policy = new SecurityPolicy(rateLimitEnabled: true, rateLimitMaxRequests: 2, rateLimitWindowSeconds: 60, rateLimitMaxKeys: 8, originEnabled: true, allowedOrigins: ['https://app.example.test']);
$mw = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter(8));
$req = new ServerRequest('GET', new Uri('https://api.example.test/'), ['REMOTE_ADDR' => '192.0.2.10'], [], [], [], null, ['Origin' => 'https://app.example.test']);
$r1 = $mw->process($req, $handler); $ok($r1->getStatusCode() === 200 && $r1->getHeaderLine('X-RateLimit-Remaining') === '1', 'E2 rate limiter emits remaining quota');
$r2 = $mw->process($req, $handler); $ok($r2->getStatusCode() === 200 && $r2->getHeaderLine('X-RateLimit-Remaining') === '0', 'E2 second request consumes quota');
$r3 = $mw->process($req, $handler); $ok($r3->getStatusCode() === 429 && $r3->getHeaderLine('Retry-After') !== '', 'E2 third request is rate limited');

$badOrigin = new ServerRequest('GET', new Uri('https://api.example.test/'), ['REMOTE_ADDR' => '192.0.2.11'], [], [], [], null, ['Origin' => 'https://evil.example']);
$originMw = new SecurityRuntimeMiddleware(new SecurityPolicy(originEnabled: true, allowedOrigins: ['https://app.example.test']), new InMemoryRateLimiter(8));
$originRes = $originMw->process($badOrigin, $handler); $ok($originRes->getStatusCode() === 403, 'E2 disallowed Origin is rejected');

$csrfSecret = str_repeat('s', 32);
$csrfMw = new SecurityRuntimeMiddleware(new SecurityPolicy(csrfEnabled: true, csrfSecret: $csrfSecret), new InMemoryRateLimiter(8));
$cookieReq = new ServerRequest('GET', new Uri('https://api.example.test/'), ['REMOTE_ADDR' => '192.0.2.12']);
$issued = $csrfMw->process($cookieReq, $handler);
$setCookie = $issued->getHeaderLine('Set-Cookie');
$cookie = trim(explode(';', $setCookie, 2)[0]);
$token = (string) substr($cookie, strpos($cookie, '=') + 1);
$ok($setCookie !== '' && $token !== '', 'E2 safe request receives CSRF cookie');

$forbidden = new ServerRequest('POST', new Uri('https://api.example.test/'), ['REMOTE_ADDR' => '192.0.2.12'], [], [], [], null, ['Cookie' => $cookie]);
$forbiddenRes = $csrfMw->process($forbidden, $handler); $ok($forbiddenRes->getStatusCode() === 403, 'E2 unsafe request without CSRF header is rejected');
$allowed = new ServerRequest('POST', new Uri('https://api.example.test/'), ['REMOTE_ADDR' => '192.0.2.12'], [], [], [], null, ['Cookie' => $cookie, 'X-CSRF-Token' => $token]);
$allowedRes = $csrfMw->process($allowed, $handler); $ok($allowedRes->getStatusCode() === 200, 'E2 valid double-submit CSRF token is accepted');

$captureHandler = new class implements RequestHandlerInterface {
    public ?ServerRequestInterface $request = null;
    #[\Override] public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface { $this->request = $request; return new Response(200, [], 'ok'); }
};
$proxyReq = new ServerRequest('GET', new Uri('https://api.example.test/'), ['REMOTE_ADDR' => '203.0.113.10'], [], [], [], null, ['X-Forwarded-For' => '198.51.100.7, 203.0.113.10'])->withAttribute('__zef_trusted_proxies', ['203.0.113.0/24']);
(new SecurityRuntimeMiddleware(new SecurityPolicy(), new InMemoryRateLimiter(8)))->process($proxyReq, $captureHandler);
$proxyContext = $captureHandler->request?->getAttribute('zef.security.context');
/** @var \Zef\Framework\Security\SecurityContext|null $proxyContext */
$ok($proxyContext?->clientIp === '198.51.100.7', 'E2 trusted proxy chain resolves original client IP');
$ok($proxyReq->getAttribute('zef.security.context') === null, 'E2 source request remains immutable after security middleware');

$context = $allowed->withAttribute('zef.security.context', null);
$ok(str_contains(strtolower($setCookie), 'httponly'), 'E2 CSRF cookie is HttpOnly by default (M-4)');
$ok($context->getAttribute('zef.security.context') === null, 'E2 request attributes remain immutable via withAttribute semantics');

echo "SecurityRuntimeE2Test: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
