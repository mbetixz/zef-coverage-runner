<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Http\Request;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\UploadedFile;
use Zef\Framework\Http\Uri;

/**
 * Batch 8 coverage: Http\Request, Http\ServerRequest, Http\Uri and
 * RequestFactory edge paths (authority parsing, front-controller handling).
 *
 * Deterministic: fromGlobals() superglobals are isolated per test and
 * restored in tearDown; no wall-clock or random state is consulted.
 */
final class Batch8RequestFactoryEdgeTest extends TestCase
{
    /** @var array<mixed> */
    private array $server = [];
    /** @var array<mixed> */
    private array $files = [];

    #[Override]
    protected function setUp(): void
    {
        // $_SERVER/$_FILES are typed array<mixed> by PHP itself; keep the
        // snapshot as-is (no narrowing) so phpstan sees an exact match.
        $this->server = $_SERVER;
        $this->files = $_FILES;
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
    }

    #[Override]
    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_FILES = $this->files;
    }

    /** @param array<string, mixed> $server */
    private function setServer(array $server): void
    {
        $_SERVER = $server;
    }

    // -------------------------------------------------- Request (Psr7 direct)

    public function testRequestInvalidMethodRejected(): void
    {
        $uri = new Uri('https://example.com/');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP method.');
        new Request('GE T', $uri);
    }

    public function testRequestEmptyMethodRejected(): void
    {
        $uri = new Uri('https://example.com/');
        $this->expectException(InvalidArgumentException::class);
        new Request('', $uri);
    }

    public function testRequestDerivesHostHeaderFromUri(): void
    {
        $request = new Request('GET', new Uri('https://example.com:8443/path'));
        $this->assertSame('example.com:8443', $request->getHeaderLine('Host'));
    }

    public function testRequestHostHeaderWinsOverUri(): void
    {
        $request = new Request('GET', new Uri('https://example.com/x'), ['Host' => 'vhost.test']);
        $this->assertSame('vhost.test', $request->getHeaderLine('Host'));
    }

    public function testRequestTargetDerivation(): void
    {
        $request = new Request('GET', new Uri('http://example.com'));
        $this->assertSame('/', $request->getRequestTarget());

        $request2 = new Request('GET', new Uri('http://example.com/a/b?q=1'));
        $this->assertSame('/a/b?q=1', $request2->getRequestTarget());

        $request3 = new Request('GET', new Uri('http://example.com/rel'), body: null, requestTarget: '/custom?x=1');
        $this->assertSame('/custom?x=1', $request3->getRequestTarget());
    }

    public function testRequestTargetWithControlCharRejected(): void
    {
        $request = new Request('GET', new Uri('http://example.com/'));
        $this->expectException(InvalidArgumentException::class);
        $request->withRequestTarget("/bad\ntarget");
    }

    public function testWithUriPreserveHostFalseOverwrites(): void
    {
        $request = new Request('GET', new Uri('http://a.example/x'), ['Host' => 'a.example']);
        $new = $request->withUri(new Uri('http://b.example:8080/y'));
        $this->assertSame('b.example:8080', $new->getHeaderLine('Host'));
        $this->assertSame('b.example:8080', $new->getUri()->getHost() . ':' . $new->getUri()->getPort());
    }

    public function testWithUriPreserveHostTrueKeepsExistingHost(): void
    {
        $request = new Request('GET', new Uri('http://a.example/x'), ['Host' => 'keep.example']);
        $new = $request->withUri(new Uri('http://b.example/y'), true);
        $this->assertSame('keep.example', $new->getHeaderLine('Host'));
    }

    public function testWithUriWithoutHostKeepsExistingHostHeader(): void
    {
        $request = new Request('GET', new Uri('http://a.example/x'));
        $this->assertSame('a.example', $request->getHeaderLine('Host'));
        // Per PSR-7, when the new URI carries no host the existing Host header
        // is preserved rather than cleared.
        $new = $request->withUri(new Uri('/relative'));
        $this->assertSame('a.example', $new->getHeaderLine('Host'));
    }

    public function testWithMethodValidates(): void
    {
        $request = new Request('GET', new Uri('http://example.com/'));
        $this->expectException(InvalidArgumentException::class);
        $request->withMethod('BAD METHOD');
    }

    // ------------------------------------------ RequestFactory authority edge

    public function testHostHeaderWithPortSeparateFromServerPort(): void
    {
        // HTTP_HOST carries the port; SERVER_PORT is ignored when Host has one.
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com:8080',
            'SERVER_PORT' => '9999',
        ]);
        $request = RequestFactory::fromGlobals();
        $this->assertSame(8080, $request->getUri()->getPort());
        $this->assertSame('example.com', $request->getUri()->getHost());
    }

    public function testHostHeaderBareIpv4WithServerPort(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => '192.168.1.10',
            'SERVER_PORT' => '8080',
        ]);
        $request = RequestFactory::fromGlobals();
        $this->assertSame('192.168.1.10', $request->getUri()->getHost());
        $this->assertSame(8080, $request->getUri()->getPort());
    }

    public function testIpv6BracketedHostWithPortAccepted(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => '[2001:db8::1]:8443',
        ]);
        $request = RequestFactory::fromGlobals();
        $this->assertSame('2001:db8::1', $request->getUri()->getHost());
        $this->assertSame(8443, $request->getUri()->getPort());
    }

    public function testMalformedIpv6PortSuffixRejected(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => '[::1]:abc',
        ]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed Host header.');
        RequestFactory::fromGlobals();
    }

    public function testEmptyPortAfterColonRejected(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com:',
        ]);
        $this->expectException(InvalidArgumentException::class);
        RequestFactory::fromGlobals();
    }

    public function testInvalidDnsHostRejected(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => '-bad-.example.com',
        ]);
        $this->expectException(InvalidArgumentException::class);
        RequestFactory::fromGlobals();
    }

    public function testOutOfRangePortRejected(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com:70000',
        ]);
        $this->expectException(InvalidArgumentException::class);
        RequestFactory::fromGlobals();
    }

    public function testNonScalarServerValuesIgnored(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
            'HTTP_X_ARRAY' => ['a', 'b'],
        ]);
        $request = RequestFactory::fromGlobals();
        $this->assertSame('example.com', $request->getUri()->getHost());
        $this->assertFalse($request->hasHeader('x-array'));
    }

    public function testServerNameFallbackWhenNoHostHeader(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SERVER_NAME' => 'fallback.example',
        ]);
        $request = RequestFactory::fromGlobals();
        $this->assertSame('fallback.example', $request->getUri()->getHost());
    }

    public function testFrontControllerPathPrefixStripped(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/app/index.php/deep/link',
            'SCRIPT_NAME' => '/app/index.php',
            'SCRIPT_FILENAME' => '/var/www/app/index.php',
            'HTTP_HOST' => 'example.com',
        ]);
        $request = RequestFactory::fromGlobals();
        $this->assertSame('/deep/link', $request->getUri()->getPath());
    }

    public function testHostExceeding253CharsRejected(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 63) . '.example.com',
        ]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed Host header.');
        RequestFactory::fromGlobals();
    }

    public function testLabelStartingWithHyphenRejected(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => '-bad.example.com',
        ]);
        $this->expectException(InvalidArgumentException::class);
        RequestFactory::fromGlobals();
    }

    public function testLabelEndingWithHyphenRejected(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'bad-.example.com',
        ]);
        $this->expectException(InvalidArgumentException::class);
        RequestFactory::fromGlobals();
    }

    // ------------------------------------------- ServerRequest + UploadedFile

    public function testServerRequestAttributeRoundTrip(): void
    {
        $uri = new Uri('http://example.com/');
        $request = new ServerRequest('GET', $uri, ['REMOTE_ADDR' => '127.0.0.1']);
        $this->assertNull($request->getAttribute('missing'));
        $this->assertSame('dflt', $request->getAttribute('missing', 'dflt'));
        $with = $request->withAttribute('k', 'v');
        $this->assertSame('v', $with->getAttribute('k'));
        $this->assertNull($request->getAttribute('k'));
        $cleared = $with->withoutAttribute('k');
        $this->assertNull($cleared->getAttribute('k'));
    }

    public function testParsedBodyAndCookieRoundTrip(): void
    {
        $request = new ServerRequest('POST', new Uri('http://example.com/'), [], ['sid' => 'abc'], ['q' => '1']);
        $this->assertSame(['sid' => 'abc'], $request->getCookieParams());
        $this->assertSame(['q' => '1'], $request->getQueryParams());
        $this->assertNull($request->getParsedBody());

        $withBody = $request->withParsedBody(['a' => 1]);
        $this->assertSame(['a' => 1], $withBody->getParsedBody());
    }

    public function testUploadedFileMoveToRoundTrip(): void
    {
        $target = tempnam(sys_get_temp_dir(), 'zef-b8-uf-');
        $this->assertNotFalse($target);
        try {
            $upload = new UploadedFile(Stream::fromString('round-trip'));
            $upload->moveTo($target);
            $this->assertSame('round-trip', (string) file_get_contents($target));
        } finally {
            @unlink($target);
        }
    }

    public function testServerRequestProtocolFromServer(): void
    {
        $uri = new Uri('http://example.com/');
        $request = new ServerRequest('GET', $uri, [], [], [], [], null, [], Stream::fromString(''), '2.0');
        $this->assertSame('2.0', $request->getProtocolVersion());
    }
}
