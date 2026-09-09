<?php

declare(strict_types=1);

namespace Zef\Test {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\App\Bootstrap;
    use Zef\Framework\Container\Container;
    use Zef\Framework\Container\ServiceLifetime;
    use Zef\Framework\Http\Response;
    use Zef\Framework\Http\ServerRequest;
    use Zef\Framework\Http\Uri;
    use Zef\Framework\MiddlewarePipeline;
    use Zef\Framework\Router\Router;

    final class CliRunner
    {
        private int $passed = 0;
        private int $failed = 0;
        public function run(bool $html = false): int
        {
            $this->banner($html);
            $this->suite('PSR contracts', fn () => $this->testPsr());
            $this->suite('Application routes', fn () => $this->testRoutes());
            $this->suite('Container lifetime/cycles', fn () => $this->testContainer());
            $this->suite('Concurrent request scope isolation', fn () => $this->testConcurrencyScopes());
            $this->suite('Security/header/URI', fn () => $this->testSecurity());
            $this->suite('Request/factory boundaries', fn () => $this->testRequestFactoryBoundaries());
            $this->suite('Router ambiguity', fn () => $this->testRouterSemantics());
            $this->suite('Pipeline/error boundary', fn () => $this->testPipeline());
            $this->suite('PSR-7 edge cases & resource safety', fn () => $this->testPsrHardening());
            $this->suite('Final production hardening', fn () => $this->testFinalHardening());
            $this->suite('JSON scalar boundary', fn () => $this->testJsonScalar());
            $this->suite('Zero critical bugs gate', fn () => $this->testZeroCriticalGate());
            $this->summary($html);
            return $this->failed === 0 ? 0 : 1;
        }
        private function testPsr(): void
        {
            // Concrete final classes are statically known to implement the PSR-7
            // interfaces, so widen the probes to plain strings/objects to keep
            // the runtime assertions meaningful without static-narrowing noise.
            /** @var string $responseClass */
            $responseClass = Response::class;
            /** @var string $requestClass */
            $requestClass = ServerRequest::class;
            $this->ok(is_subclass_of($responseClass, ResponseInterface::class), 'Response implements PSR-7 ResponseInterface');
            $this->ok(is_subclass_of($requestClass, ServerRequestInterface::class), 'ServerRequest implements PSR-7 ServerRequestInterface');
            $c = new \Zef\Framework\Http\Psr17Factory();
            /** @var ResponseInterface $response */
            $response = $c->createResponse();
            $this->ok($response->getStatusCode() === 200, 'PSR-17 response factory');
            $this->ok($c->createStream('abc')->__toString() === 'abc', 'PSR-17 stream factory');
            $msg = (new Response(200, ['X-Test' => 'one']))->withHeader('x-test', 'two');
            $this->ok($msg->getHeaderLine('X-TEST') === 'two' && array_key_exists('x-test', $msg->getHeaders()), 'case-insensitive header replacement preserves supplied case');
            $added = $msg->withAddedHeader('X-TEST', 'three');
            $this->ok($added->getHeaderLine('x-test') === 'two, three' && count($added->getHeaders()) === 1, 'case-insensitive withAddedHeader cannot create duplicate keys');
            $this->ok($added->withoutHeader('x-Test')->getHeaders() === [], 'header dictionary removes canonical key');

        }
        private function testRoutes(): void
        {
            $app = Bootstrap::createApp(false);
            $app->boot();
            foreach ([['GET','/',200],['GET','/about',200],['GET','/toko',200],['GET','/toko/produk/2',200],['GET','/missing',404],['GET','/toko/produk/abc',400]] as [$m,$p,$s]) {
                $r = $app->handle(new ServerRequest($m, new Uri('http://localhost' . $p, ['localhost'])));
                $this->ok($r->getStatusCode() === $s, "{$m} {$p} -> {$s}");
            }
            /** @var \Zef\Framework\Http\Response $detail */
            $detail = $app->handle(new ServerRequest('GET', new Uri('http://localhost/toko/produk/2', ['localhost'])));
            $this->ok(str_contains($detail->bodyString(), 'Mouse Wireless'), 'detail body');
            $this->ok($detail->hasHeader('x-request-id'), 'correlation ID');
            $this->ok($detail->hasHeader('x-content-type-options'), 'security middleware');
        }
        private function testContainer(): void
        {
            $c = new Container();
            $c->register('a', static fn () => new \stdClass(), [], 'm', ServiceLifetime::SINGLETON);
            $c->register('b', static fn ($x, $a) => new \stdClass(), ['a'], 'm', ServiceLifetime::REQUEST);
            $c->validateAndFreeze();
            $s1 = $c->createRequestScope();
            $b1 = $s1->get('b');
            $this->ok($s1->get('b') === $b1, 'request scope singleton within scope');
            $s1->close();
            $s2 = $c->createRequestScope();
            $this->ok($s2->get('b') !== $b1, 'request scope cleared between scopes');
            $s2->close();
            $this->ok($c->get('a') === $c->get('a'), 'singleton stable');
            $cycle = new Container();
            $cycle->register('x', static fn ($c, $y) => new \stdClass(), ['y'], 'm');
            $cycle->register('y', static fn ($c, $x) => new \stdClass(), ['x'], 'm');
            $this->throws(\Zef\Framework\Exception\ServiceCircularDependencyException::class, fn () => $cycle->validateAndFreeze(), 'boot dependency cycle');
        }
        private function testConcurrencyScopes(): void
        {
            $c = new Container();
            $c->register('request.counter', static fn () => new \stdClass(), [], 'test', ServiceLifetime::REQUEST);
            $c->register('singleton.bad', static fn ($c, $x) => new \stdClass(), ['request.counter'], 'test', ServiceLifetime::SINGLETON);
            $this->throws(\Zef\Framework\Exception\InvalidConfigurationException::class, fn () => $c->validateAndFreeze(), 'captive request dependency rejected');

            $c2 = new Container();
            $c2->register('request.counter', static fn () => new \stdClass(), [], 'test', ServiceLifetime::REQUEST);
            $c2->validateAndFreeze();
            $a = $c2->createRequestScope();
            $b = $c2->createRequestScope();
            $a1 = $a->get('request.counter');
            $a2 = $a->get('request.counter');
            $b1 = $b->get('request.counter');
            $this->ok($a1 === $a2, 'request scope stable within scope');
            $this->ok($a1 !== $b1, 'independent request scopes do not share instances');
            $a->close();
            $b->close();
        }

        private function testSecurity(): void
        {
            $r = new Response();
            $this->throws(\InvalidArgumentException::class, fn () => $r->withHeader("X-Bad\r\nX-Evil", 'x'), 'header-name CRLF blocked');
            $this->throws(\InvalidArgumentException::class, fn () => $r->withHeader('X-Bad', "x\r\ny"), 'header-value CRLF blocked');
            $u = new Uri('http://localhost/a', ['localhost']);
            $this->ok($u->withPath('/x/y')->getPath() === '/x/y', 'URI immutable path');
            $this->throws(\InvalidArgumentException::class, fn () => new Uri('http://evil.test/', ['localhost']), 'trusted host enforced');
            $this->throws(\InvalidArgumentException::class, fn () => $u->withPort(70000), 'port range');
            $rv = new \Zef\Framework\Validation\RouteConstraintValidator();
            $this->throws(\Zef\Framework\Exception\InvalidConfigurationException::class, fn () => $rv->addCustom('broken', '/^[0-9/'), 'malformed custom regex rejected without warning leakage');
        }
        private function testPsrHardening(): void
        {
            $factory = new \Zef\Framework\Http\Psr17Factory();

            $req = $factory->createServerRequest('GET', 'http://localhost/')->withoutHeader('Host');
            $newUri = $factory->createUri('http://zef.test');
            $mutated = $req->withUri($newUri, true);
            $this->ok($mutated->getHeaderLine('Host') === 'zef.test', 'PSR-7: missing Host populated with preserveHost=true');

            $emptyHost = $factory->createServerRequest('GET', 'http://localhost/')->withHeader('Host', '');
            $this->ok($emptyHost->withUri($newUri, true)->getHeaderLine('Host') === 'zef.test', 'PSR-7: empty Host populated with preserveHost=true');

            $preserved = $factory->createServerRequest('GET', 'http://localhost/')->withHeader('Host', 'legacy.test');
            $this->ok($preserved->withUri($newUri, true)->getHeaderLine('Host') === 'legacy.test', 'PSR-7: non-empty Host preserved');

            $parsed = $factory->createServerRequest('POST', '/')->withParsedBody(['x' => 1]);
            $this->ok($parsed->getParsedBody() === ['x' => 1], 'PSR-7: array parsed body accepted');
            $this->ok($parsed->withParsedBody(null)->getParsedBody() === null, 'PSR-7: null parsed body accepted');
            // Negative-contract probe: the scalar is rejected at runtime by assertParsedBodyShape.
            $this->throws(\InvalidArgumentException::class, fn () => $parsed->withParsedBody('invalid'), 'PSR-7: scalar parsed body rejected'); // @phpstan-ignore argument.type

            $resource = fopen('php://temp', 'w+b');
            if ($resource === false) {
                throw new \RuntimeException('fopen(php://temp) failed for emit-close-contract probe');
            }
            fwrite($resource, 'emit-close-contract');
            $stream = $factory->createStreamFromResource($resource);
            $response = $factory->createResponse(200)->withBody($stream);
            ob_start();
            (new \Zef\Framework\ResponseEmitter())->emit($response);
            ob_end_clean();
            $this->ok($stream->isReadable(), 'Emitter: borrowed stream remains open by default');

            $resource2 = fopen('php://temp', 'w+b');
            if ($resource2 === false) {
                throw new \RuntimeException('fopen(php://temp) failed for explicit-close probe');
            }
            fwrite($resource2, 'explicit-close');
            $stream2 = $factory->createStreamFromResource($resource2);
            $response2 = $factory->createResponse(200)->withBody($stream2);
            ob_start();
            (new \Zef\Framework\ResponseEmitter())->emit($response2, true);
            ob_end_clean();
            $this->throws(\RuntimeException::class, fn () => $stream2->tell(), 'Emitter: explicit close invalidates stream deterministically');

            $agg = new \Zef\Framework\Config\ConfigAggregator();
            $provider = new class () implements \Zef\Framework\Config\ConfigProviderInterface {
                #[\Override]
                public function getModuleName(): string
                {
                    return 'core';
                }
                #[\Override]
                public function getConfig(): array
                {
                    return [];
                }
            };
            $provider2 = new class () implements \Zef\Framework\Config\ConfigProviderInterface {
                #[\Override]
                public function getModuleName(): string
                {
                    return 'Core';
                }
                #[\Override]
                public function getConfig(): array
                {
                    return [];
                }
            };
            $agg->addProvider($provider);
            $this->throws(\Zef\Framework\Exception\InvalidConfigurationException::class, fn () => $agg->addProvider($provider2), 'Config: module collision is case-insensitive');
        }

        private function testRequestFactoryBoundaries(): void
        {
            $factory = new \Zef\Framework\Http\Psr17Factory();
            $req = $factory->createRequest('GET', 'http://localhost/demo');
            $this->ok($req->getHeaderLine('Host') === 'localhost', 'request factory populates Host from URI');
            $this->ok($req->withMethod('PATCH')->getMethod() === 'PATCH', 'HTTP method case preserved');
            $this->ok($req->getRequestTarget() === '/demo', 'request target defaults to origin-form');
            $retargeted = $req->withUri($factory->createUri('http://localhost/other?q=1'));
            $this->ok($retargeted->getRequestTarget() === '/other?q=1', 'withUri updates derived request target');
            $explicit = $req->withRequestTarget('*')->withUri($factory->createUri('http://localhost/other'));
            $this->ok($explicit->getRequestTarget() === '*', 'explicit request target survives withUri');
            $emptyTarget = $req->withRequestTarget('');
            $this->ok($emptyTarget->getRequestTarget() === '', 'explicit empty request target retained verbatim');
            $this->throws(\InvalidArgumentException::class, fn () => $factory->createStreamFromFile('/does/not/exist', 'q'), 'invalid stream mode rejected');

            $container = new Container();
            $container->register('request.s', fn () => new \stdClass(), [], 't', ServiceLifetime::REQUEST);
            $container->validateAndFreeze();
            $a = $container->createRequestScope();
            $b = $container->createRequestScope();
            $aa = $a->get('request.s');
            $bb = $b->get('request.s');
            $this->ok($aa !== $bb, 'independent request scopes do not share mutable state');
            $a->close();
            $b->close();
        }

        private function testRouterSemantics(): void
        {
            $r = new Router();
            $r->add('GET', '/user/{id:int}', 'dynamic', priority:0);
            $r->add('GET', '/user/admin', 'static', priority:10);
            $m = $r->match('GET', '/user/admin');
            $this->ok($m['handler'] === 'static', 'static route wins over constraint mismatch');
            $this->throws(\Zef\Framework\Exception\RouteConstraintException::class, fn () => $r->match('GET', '/user/abc'), 'constraint failure produces 400-class exception');
        }
        private function testPipeline(): void
        {
            $terminal = new class () implements RequestHandlerInterface {
                #[\Override]
                public function handle(ServerRequestInterface $r): ResponseInterface
                {
                    throw new \RuntimeException('boom');
                }
            };
            $mw = new \Zef\Middleware\GlobalErrorHandler(new \Psr\Log\NullLogger(), new \Zef\Middleware\ErrorResponseFactory(false));
            $p = (new MiddlewarePipeline([], $terminal))->withMiddleware($mw);
            $res = $p->handle(new ServerRequest('GET', new Uri('http://localhost/', ['localhost'])));
            $this->ok($res->getStatusCode() === 500, 'error boundary catches terminal exception');
        }
        private function testFinalHardening(): void
        {
            // URI scheme/control and IPv6 authority
            $u = new Uri('http://localhost/', ['localhost','::1']);
            $this->throws(\InvalidArgumentException::class, fn () => $u->withScheme("http\r\n"), 'URI scheme rejects control chars');
            $ipv6 = $u->withHost('[::1]');
            $this->ok($ipv6->getHost() === '::1' && $ipv6->getAuthority() === '[::1]', 'IPv6 host authority normalized');
            $relative = new Uri('foo/bar');
            $this->ok($relative->getPath() === 'foo/bar' && (string) $relative === 'foo/bar', 'URI rootless path preserved per PSR-7');
            $this->ok((new \Zef\Framework\Http\Request('GET', $relative))->getRequestTarget() === '/foo/bar', 'request target derives origin-form from rootless URI');
            $encoded = (new Uri())->withPath('/hello world')->withQuery('q=a b');
            $this->ok($encoded->getPath() === '/hello%20world' && $encoded->getQuery() === 'q=a%20b', 'URI components are percent-encoded');

            // Stream cursor is restored by string conversion.
            $st = \Zef\Framework\Http\Stream::fromString('abcdef');
            $st->seek(2);
            $this->ok((string) $st === 'abcdef' && $st->tell() === 6, 'Stream::__toString__ rewinds and reads to EOF per PSR-7');

            // Uploaded file failure must remain retryable and must remove partial destination.
            $upStream = \Zef\Framework\Http\Stream::fromString('upload');
            $up = new \Zef\Framework\Http\UploadedFile($upStream);
            $bad = sys_get_temp_dir() . '/zef-missing-dir/' . bin2hex(random_bytes(4));
            $this->throws(\RuntimeException::class, fn () => $up->moveTo($bad), 'upload move failure does not poison object');
            $this->ok(!$upStream->eof(), 'source stream remains usable after failed move');

            // Transitive captive dependency prevention.
            $c = new Container();
            $c->register('req', static fn () => new \stdClass(), [], 'm', ServiceLifetime::REQUEST);
            $c->register('mid', static fn ($c, $r) => new \stdClass(), ['req'], 'm', ServiceLifetime::SINGLETON);
            $c->register('top', static fn ($c, $m) => new \stdClass(), ['mid'], 'm', ServiceLifetime::SINGLETON);
            $this->throws(\Zef\Framework\Exception\InvalidConfigurationException::class, fn () => $c->validateAndFreeze(), 'transitive captive dependency rejected');

            // Router structural duplicate and 405 semantics.
            $r = new Router();
            $r->add('GET', '/user/{id:int}', 'a');
            $this->throws(\InvalidArgumentException::class, fn () => $r->add('GET', '/user/{name:int}', 'b'), 'structural dynamic route collision rejected');
            $r->add('POST', '/user/{id:int}', 'p');
            $this->throws(\Zef\Framework\Exception\MethodNotAllowedException::class, fn () => $r->match('PUT', '/user/7'), '405 route method mismatch detected');

            // Scope closed boundary.
            $sc = $c2 = new Container();
            $sc->register('r', static fn () => new \stdClass(), [], 'm', ServiceLifetime::REQUEST);
            $sc->validateAndFreeze();
            $scope = $sc->createRequestScope();
            $scope->close();
            $this->throws(\LogicException::class, fn () => $scope->get('r'), 'closed request scope rejects access');
            $corsOff = new \Zef\Middleware\CorsMiddleware(null);
            $corsReq = new ServerRequest('GET', new Uri('http://localhost/', ['localhost']));
            $corsRes = $corsOff->process($corsReq, new class () implements RequestHandlerInterface {
                #[\Override]
                public function handle(ServerRequestInterface $r): ResponseInterface
                {
                    return new Response(200, [], 'ok');
                }
            });
            $this->ok(!$corsRes->hasHeader('Access-Control-Allow-Origin'), 'CORS disabled by default');
            $corsOn = new \Zef\Middleware\CorsMiddleware('https://example.test');
            $corsReqOrigin = new ServerRequest('GET', new Uri('http://localhost/', ['localhost']), [], [], [], [], null, ['Origin' => 'https://example.test']);
            $corsRes2 = $corsOn->process($corsReqOrigin, new class () implements RequestHandlerInterface {
                #[\Override]
                public function handle(ServerRequestInterface $r): ResponseInterface
                {
                    return new Response(200, ['Vary' => 'Accept-Encoding'], 'ok');
                }
            });
            $this->ok($corsRes2->getHeaderLine('Access-Control-Allow-Origin') === 'https://example.test' && $corsRes2->getHeaderLine('Vary') === 'Accept-Encoding, Origin', 'CORS opt-in merges existing Vary');
        }

        private function testJsonScalar(): void
        {
            $factory = new \Zef\Framework\Http\Psr17Factory();
            $req = $factory->createServerRequest('POST', 'http://localhost/', ['CONTENT_TYPE' => 'application/json']);
            $stream = $factory->createStream('123');
            $req = $req->withBody($stream);
            $this->ok(\Zef\Framework\Http\RequestFactory::decodeJsonBody($req) === 123, 'JSON scalar remains available through explicit decoder');
            // Negative-contract probe: withParsedBody(int) must be rejected at runtime per PSR-7.
            $this->throws(\InvalidArgumentException::class, fn () => $req->withParsedBody(123), 'PSR parsedBody contract still rejects scalar'); // @phpstan-ignore argument.type
        }

        private function testZeroCriticalGate(): void
        {
            // PSR-11 has() must be true for registered request services even outside scope.
            $c = new \Zef\Framework\Container\Container();
            $c->register('req', static fn () => new \stdClass(), [], 'gate', \Zef\Framework\Container\ServiceLifetime::REQUEST);
            $c->validateAndFreeze();
            $this->ok($c->has('req') === true, 'PSR-11 has() reports registered request service');
            $this->throws(\Psr\Container\ContainerExceptionInterface::class, fn () => $c->get('req'), 'request service outside scope is a ContainerException');

            // URI malformed percent escapes must be encoded, not emitted verbatim.
            $badPct = (new \Zef\Framework\Http\Uri())->withPath('/x%ZZ');
            $this->ok($badPct->getPath() === '/x%25ZZ', 'malformed percent escape is encoded');
            $this->throws(\InvalidArgumentException::class, fn () => new \Zef\Framework\Http\Request('', $badPct), 'empty request method rejected');

            // Protocol constructor validation.
            $this->throws(\InvalidArgumentException::class, fn () => new \Zef\Framework\Http\Response(200, [], '', '', 'not-http'), 'invalid protocol rejected in constructor');

            // Route custom constraints are validated at registration, not first request.
            $r = new \Zef\Framework\Router\Router();
            $this->throws(\Zef\Framework\Exception\InvalidConfigurationException::class, fn () => $r->add('GET', '/x/{id:missing}', 'h'), 'unknown route constraint rejected during registration');
            $r->addConstraint('digits', '/^\\d+$/');
            $r->add('GET', '/x/{id:digits}', 'h');
            $this->throws(\InvalidArgumentException::class, fn () => $r->add('GET', '/x/{other:digits}', 'h2'), 'structural duplicate remains rejected');

            // CORS Vary de-duplication.
            $cors = new \Zef\Middleware\CorsMiddleware('https://example.test');
            $res = $cors->process(new \Zef\Framework\Http\ServerRequest('GET', new \Zef\Framework\Http\Uri('http://localhost/', ['localhost'])), new class () implements \Psr\Http\Server\RequestHandlerInterface {
                #[\Override]
                public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface
                {
                    return new \Zef\Framework\Http\Response(200, ['Vary' => 'Accept-Encoding, Origin'], 'ok');
                }
            });
            $this->ok($res->getHeaderLine('Vary') === 'Accept-Encoding, Origin', 'CORS does not duplicate Vary token');

            // Scope context is reset after close and singleton warm-up is deterministic.
            $sc = $c->createRequestScope();
            $first = $sc->get('req');
            $sc->close();
            $this->throws(\LogicException::class, fn () => $sc->get('req'), 'closed scope rejects resolution');
        }

        private function suite(string $name, callable $test): void
        {
            try {
                $test();
                $this->out("[PASS] {$name}");
            } catch (\Throwable $e) {
                $this->failed++;
                $this->out("[FAIL] {$name}: {$e->getMessage()}");
            }
        }
        private function ok(bool $cond, string $label): void
        {
            if ($cond) {
                $this->passed++;
                $this->out('  ✔ ' . $label);
            } else {
                $this->failed++;
                $this->out('  ✘ ' . $label);
            }
        }
        private function throws(string $class, callable $fn, string $label): void
        {
            try {
                $fn();
                $this->ok(false,$label . ' (no exception)');
            } catch (\Throwable $e) {
                $this->ok($e instanceof $class,$label . ' -> ' . get_class($e));
            }
        }
        private function out(string $line): void
        {
            echo $line . (PHP_SAPI === 'cli' ? "\n" : "<br>\n");
        }
        private function banner(bool $html): void
        {
            if ($html) {
                echo '<!doctype html><html><head><meta charset="utf-8"><title>ZEF self-test</title><style>body{font-family:system-ui;background:#111;color:#eee;padding:24px}pre{white-space:pre-wrap}</style></head><body><pre>';
            } echo "ZEF Framework v".\Zef\Framework\Foundation\ZefVersion::VERSION." — SELF TEST\n";
        }
        private function summary(bool $html): void
        {
            $this->out("\nPASSED: {$this->passed}  FAILED: {$this->failed}");
            if ($html) {
                echo '</pre></body></html>';
            }$GLOBALS['zef_test_summary'] = ['passed' => $this->passed,'failed' => $this->failed];
        }
    }
}