<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Zef\Framework\Exception\PayloadTooLargeException;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\Uri;

final class RequestFactoryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];
    /** @var array<string, mixed> */
    private array $get = [];
    /** @var array<string, mixed> */
    private array $post = [];
    /** @var array<string, string> */
    private array $cookie = [];
    /** @var array<string, mixed> */
    private array $files = [];

    #[Override]
    protected function setUp(): void
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        $this->server = $server;
        /** @var array<string, mixed> $get */
        $get = $_GET;
        $this->get = $get;
        /** @var array<string, mixed> $post */
        $post = $_POST;
        $this->post = $post;
        /** @var array<string, string> $cookie */
        $cookie = $_COOKIE;
        $this->cookie = $cookie;
        /** @var array<string, mixed> $files */
        $files = $_FILES;
        $this->files = $files;
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
        $_GET = $this->get;
        $_POST = $this->post;
        $_COOKIE = $this->cookie;
        $_FILES = $this->files;
    }

    /**
     * @param array<string, string|list<string>> $overrides
     */
    private function setServer(array $overrides = []): void
    {
        $_SERVER = array_merge([
            'REQUEST_METHOD' => 'GET',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/var/www/index.php',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $overrides);
    }

    public function testBuildsServerRequestFromGlobals(): void
    {
        $this->setServer(['REQUEST_URI' => '/api/users?page=2']);

        $request = RequestFactory::fromGlobals();

        $this->assertInstanceOf(ServerRequest::class, $request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('example.com', $request->getUri()->getHost());
        $this->assertSame('/api/users', $request->getUri()->getPath());
        $this->assertSame('page=2', $request->getUri()->getQuery());
        $this->assertSame('/api/users?page=2', $request->getRequestTarget());
        $this->assertSame('example.com', $request->getHeaderLine('Host'));
    }

    public function testReadsMethodProtocolAndPort(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'post',
            'SERVER_PROTOCOL' => 'HTTP/2.0',
            'HTTP_HOST' => 'example.com:8080',
        ]);

        $request = RequestFactory::fromGlobals();

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('2.0', $request->getProtocolVersion());
        $this->assertSame('http', $request->getUri()->getScheme());
        $this->assertSame(8080, $request->getUri()->getPort());
    }

    public function testHttpsSchemeDetected(): void
    {
        $this->setServer(['HTTPS' => 'on']);

        $this->assertSame('https', RequestFactory::fromGlobals()->getUri()->getScheme());
    }

    public function testCookiesQueryAndUploadsPassThrough(): void
    {
        $this->setServer(['REQUEST_URI' => '/x?q=1']);
        $_COOKIE = ['sid' => 'abc'];
        $_GET = ['q' => '1'];
        $_FILES = [
            'avatar' => [
                'name' => 'a.png',
                'type' => 'image/png',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_NO_FILE,
                'size' => 0,
            ],
        ];

        $request = RequestFactory::fromGlobals();

        $this->assertSame(['sid' => 'abc'], $request->getCookieParams());
        $this->assertSame(['q' => '1'], $request->getQueryParams());
        $this->assertInstanceOf(UploadedFileInterface::class, $request->getUploadedFiles()['avatar']);
    }

    public function testParsedBodyFromFormPost(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => '7',
        ]);
        $_POST = ['name' => 'zef'];

        $request = RequestFactory::fromGlobals();

        $this->assertSame(['name' => 'zef'], $request->getParsedBody());
    }

    public function testTrustedProxyForwardedHostAndProto(): void
    {
        $this->setServer([
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_HOST' => 'proxy.example',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $request = RequestFactory::fromGlobals([], ['10.0.0.0/24']);

        $this->assertSame('proxy.example', $request->getUri()->getHost());
        $this->assertSame('https', $request->getUri()->getScheme());
    }

    public function testUntrustedProxyForwardedHeadersIgnored(): void
    {
        $this->setServer([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $request = RequestFactory::fromGlobals([], ['10.0.0.0/24']);

        $this->assertSame('example.com', $request->getUri()->getHost());
        $this->assertSame('http', $request->getUri()->getScheme());
    }

    public function testInvalidForwardedProtocolRejected(): void
    {
        $this->setServer([
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_PROTO' => 'ftp',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        RequestFactory::fromGlobals([], ['10.0.0.0/24']);
    }

    public function testUntrustedHostRejectedWhenTrustedHostsConfigured(): void
    {
        $this->setServer(['HTTP_HOST' => 'evil.example']);

        $this->expectException(\InvalidArgumentException::class);
        RequestFactory::fromGlobals(['example.com']);
    }

    public function testMalformedRequestUriRejected(): void
    {
        $this->setServer(['REQUEST_URI' => '/api']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'ftp';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';

        $this->expectException(\InvalidArgumentException::class);
        RequestFactory::fromGlobals([], ['10.0.0.0/24']);
    }

    public function testNonScalarServerValueIgnored(): void
    {
        $this->setServer(['REQUEST_URI' => '/', 'HTTP_X_ARRAY' => ['a']]);

        $request = RequestFactory::fromGlobals();

        $this->assertFalse($request->hasHeader('x-array'));
    }

    public function testHostHeaderInjectionRejected(): void
    {
        $this->setServer(['HTTP_HOST' => "example.com\r\nX-Evil: 1"]);

        $this->expectException(\InvalidArgumentException::class);
        RequestFactory::fromGlobals();
    }

    public function testOversizedContentLengthThrows(): void
    {
        $this->setServer(['CONTENT_LENGTH' => '100']);

        $this->expectException(PayloadTooLargeException::class);
        RequestFactory::fromGlobals([], [], new RequestBodyPolicy(10));
    }

    public function testDecodeJsonBodyAssociative(): void
    {
        $request = new ServerRequest('POST', new Uri('http://example.com/'), body: Stream::fromString('{"a":1}'));

        $this->assertSame(['a' => 1], RequestFactory::decodeJsonBody($request));
    }

    public function testDecodeJsonBodyAsObject(): void
    {
        $request = new ServerRequest('POST', new Uri('http://example.com/'), body: Stream::fromString('{"a":1}'));

        $decoded = RequestFactory::decodeJsonBody($request, false);
        $this->assertIsObject($decoded);
        $this->assertSame(['a' => 1], (array) $decoded);
    }

    public function testDecodeJsonBodyEmptyReturnsNull(): void
    {
        $request = new ServerRequest('POST', new Uri('http://example.com/'), body: Stream::fromString(''));

        $this->assertNull(RequestFactory::decodeJsonBody($request));
    }

    public function testDecodeJsonBodyMalformedThrows(): void
    {
        $request = new ServerRequest('POST', new Uri('http://example.com/'), body: Stream::fromString('{"a":'));

        $this->expectException(\InvalidArgumentException::class);
        RequestFactory::decodeJsonBody($request);
    }

    public function testDecodeJsonBodyRestoresStreamPosition(): void
    {
        $body = Stream::fromString('{"a":1}');
        $request = new ServerRequest('POST', new Uri('http://example.com/'), body: $body);

        RequestFactory::decodeJsonBody($request);
        $this->assertSame(0, $body->tell());
    }
}
