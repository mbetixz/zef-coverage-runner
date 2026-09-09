<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/zef_framework_v2.5.0-beta1.php';

use Zef\Framework\Http\LimitedInputStream;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\Stream;
use Zef\Framework\Exception\PayloadTooLargeException;

$pass = 0; $fail = 0;
$ok = static function (bool $condition, string $label) use (&$pass, &$fail): void {
    if ($condition) { ++$pass; echo "PASS: {$label}\n"; }
    else { ++$fail; echo "FAIL: {$label}\n"; }
};
$throws = static function (string $class, callable $fn, string $label) use ($ok): void {
    try { $fn(); $ok(false, $label . ' (no exception)'); }
    catch (Throwable $e) { $ok($e instanceof $class, $label . ' -> ' . get_class($e)); }
};

$globals = static function(array $server): mixed {
    $oldServer = $_SERVER;
    $oldGet = $_GET;
    $oldPost = $_POST;
    $oldFiles = $_FILES;
    try {
        $_SERVER = $server + [
            'REQUEST_METHOD' => 'GET', 'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_URI' => '/', 'SERVER_NAME' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1', 'SCRIPT_NAME' => '', 'SCRIPT_FILENAME' => '',
        ];
        $_GET = []; $_POST = []; $_FILES = [];
        return RequestFactory::fromGlobals();
    } finally {
        $_SERVER = $oldServer; $_GET = $oldGet; $_POST = $oldPost; $_FILES = $oldFiles;
    }
};

// S-01 Host authority must never be interpreted as URI userinfo@host syntax.
$throws(InvalidArgumentException::class, static function() use ($globals): void {
    $globals(['HTTP_HOST' => 'trusted.example@evil.example']);
}, 'S-01 userinfo Host authority rejected');
$throws(InvalidArgumentException::class, static function() use ($globals): void {
    $globals(['HTTP_HOST' => 'evil.example/redirect']);
}, 'S-01 path-bearing Host authority rejected');

// S-02 IPv6 proxy CIDR must be trusted using binary network matching.
$request = $globals([
    'HTTP_HOST' => 'internal.example',
    'HTTP_X_FORWARDED_HOST' => 'public.example:8443',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'REMOTE_ADDR' => '2001:db8::42',
]);
$uri = $request->getUri();
$ok($uri->getHost() === 'internal.example', 'S-02 baseline RequestFactory leaves forwarded host untrusted without configured proxy');

$oldServer = $_SERVER; $oldGet = $_GET; $oldPost = $_POST; $oldFiles = $_FILES;
try {
    $_SERVER = [
        'REQUEST_METHOD'=>'GET','SERVER_PROTOCOL'=>'HTTP/1.1','REQUEST_URI'=>'/x?token=secret',
        'HTTP_HOST'=>'internal.example','HTTP_X_FORWARDED_HOST'=>'public.example:8443',
        'HTTP_X_FORWARDED_PROTO'=>'https','REMOTE_ADDR'=>'2001:db8::42','SCRIPT_NAME'=>'','SCRIPT_FILENAME'=>'',
    ];
    $_GET=[]; $_POST=[]; $_FILES=[];
    $trustedRequest = RequestFactory::fromGlobals([], ['2001:db8::/32']);
    $ok($trustedRequest->getUri()->getHost() === 'public.example', 'S-02 IPv6 trusted proxy accepts forwarded host');
    $ok($trustedRequest->getUri()->getPort() === 8443, 'S-02 forwarded port normalized');
    $ok($trustedRequest->getUri()->getScheme() === 'https', 'S-02 forwarded scheme accepted only from trusted proxy');
} finally {
    $_SERVER = $oldServer; $_GET = $oldGet; $_POST = $oldPost; $_FILES = $oldFiles;
}

// S-03 Request-body accounting cannot be bypassed by rewinding a seekable stream.
$stream = new LimitedInputStream(Stream::fromString('12345678'), new RequestBodyPolicy(8));
$ok($stream->read(4) === '1234', 'S-03 first bounded read succeeds');
$stream->rewind();
$throws(PayloadTooLargeException::class, static fn() => $stream->getContents(), 'S-03 rewind cannot reset security byte accounting');

// S-04 Exception telemetry never records the full URI/query string.
$middleware = file_get_contents($root . '/src/Middleware/ErrorLogger.php');
$ok(is_string($middleware) && !str_contains($middleware, "'uri'=>(string)\$request->getUri()"), 'S-04 full URI is not logged');
$pathLog = "'path'=>" . '$request->getUri()->getPath()';
$ok(is_string($middleware) && str_contains($middleware, $pathLog), 'S-04 only path is logged');

// S-05 security response baseline is configurable without expanding the public class surface.
$source = file_get_contents($root . '/src/Middleware/SecurityHeadersMiddleware.php');
$ok(is_string($source) && str_contains($source, "Permissions-Policy"), 'S-05 Permissions-Policy baseline available');
$ok(is_string($source) && str_contains($source, "Strict-Transport-Security"), 'S-05 HSTS capability available');
$ok(is_string($source) && str_contains($source, "Content-Security-Policy"), 'S-05 CSP capability available');

echo "SecurityHardeningTest: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
