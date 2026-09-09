<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Zef\Framework\Http\Psr17Factory;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\Uri;

final class ServerRequestTest extends TestCase
{
    private function makeRequest(string $method = 'GET', string $uri = 'http://example.com/'): ServerRequest
    {
        return new ServerRequest($method, new Uri($uri));
    }

    public function testConstructorAddsHostHeaderFromUri(): void
    {
        $request = $this->makeRequest();

        $this->assertTrue($request->hasHeader('Host'));
        $this->assertSame('example.com', $request->getHeaderLine('Host'));
        $this->assertSame('GET', $request->getMethod());
    }

    public function testConstructorHostHeaderIncludesPort(): void
    {
        $request = $this->makeRequest('GET', 'http://example.com:8080/x');

        $this->assertSame('example.com:8080', $request->getHeaderLine('Host'));
    }

    public function testConstructorNormalizesMethodToUppercase(): void
    {
        $request = $this->makeRequest('post');

        $this->assertSame('POST', $request->getMethod());
    }

    public function testConstructorRejectsScalarParsedBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ServerRequest('GET', new Uri('http://example.com/'), parsedBody: 'not-allowed');
    }

    public function testRequestTargetDerivedFromUri(): void
    {
        $request = new ServerRequest('GET', new Uri('http://example.com/api/users?page=2'));

        $this->assertSame('/api/users?page=2', $request->getRequestTarget());
    }

    public function testRequestTargetDefaultsToSlashWhenPathEmpty(): void
    {
        $request = new ServerRequest('GET', new Uri('http://example.com'));

        $this->assertSame('/', $request->getRequestTarget());
    }

    public function testRequestTargetOverrideWins(): void
    {
        $request = new ServerRequest('GET', new Uri('http://example.com/'), requestTarget: 'OPTIONS *');

        $this->assertSame('OPTIONS *', $request->getRequestTarget());
    }

    public function testWithRequestTargetRejectsControlCharacters(): void
    {
        $request = $this->makeRequest();

        $this->expectException(\InvalidArgumentException::class);
        $request->withRequestTarget("/path\ninjection");
    }

    public function testWithMethodKeepsValidToken(): void
    {
        $request = $this->makeRequest();

        $this->assertSame('PATCH', $request->withMethod('PATCH')->getMethod());
        $this->assertSame('GET', $request->getMethod(), 'original instance unchanged');
    }

    public function testWithMethodRejectsInvalidToken(): void
    {
        $request = $this->makeRequest();

        $this->expectException(\InvalidArgumentException::class);
        $request->withMethod('GET /path');
    }

    public function testWithUriReplacesHostHeaderByDefault(): void
    {
        $request = $this->makeRequest();
        $moved = $request->withUri(new Uri('https://other.example:8443/x'));

        $this->assertSame('other.example', $moved->getUri()->getHost());
        $this->assertSame('other.example:8443', $moved->getHeaderLine('Host'));
    }

    public function testWithUriPreservesHostWhenRequested(): void
    {
        $request = $this->makeRequest();
        $kept = $request->withUri(new Uri('https://other.example/x'), true);

        $this->assertSame('other.example', $kept->getUri()->getHost());
        $this->assertSame('example.com', $kept->getHeaderLine('Host'));
    }

    public function testServerCookieAndQueryParams(): void
    {
        $request = new ServerRequest(
            'GET',
            new Uri('http://example.com/'),
            ['REMOTE_ADDR' => '10.0.0.1'],
            ['sid' => 'abc'],
            ['page' => '2'],
        );

        $this->assertSame(['REMOTE_ADDR' => '10.0.0.1'], $request->getServerParams());
        $this->assertSame(['sid' => 'abc'], $request->getCookieParams());
        $this->assertSame(['page' => '2'], $request->getQueryParams());
        $this->assertSame(['page' => '3'], $request->withQueryParams(['page' => '3'])->getQueryParams());
        $this->assertSame(['sid' => 'xyz'], $request->withCookieParams(['sid' => 'xyz'])->getCookieParams());
    }

    public function testUploadedFilesTreeValidated(): void
    {
        $request = $this->makeRequest();
        $factory = new Psr17Factory();
        $upload = $factory->createUploadedFile(Stream::fromString('data'), 4, UPLOAD_ERR_OK, 'a.txt', 'text/plain');

        $withUpload = $request->withUploadedFiles(['avatar' => $upload]);

        $this->assertInstanceOf(UploadedFileInterface::class, $withUpload->getUploadedFiles()['avatar']);
        $this->assertSame([], $request->getUploadedFiles());
    }

    public function testWithUploadedFilesRejectsInvalidLeaf(): void
    {
        $request = $this->makeRequest();

        $this->expectException(\InvalidArgumentException::class);
        $request->withUploadedFiles(['bad' => 'not-an-upload']);
    }

    public function testParsedBodyRoundTrip(): void
    {
        $request = $this->makeRequest();

        $this->assertNull($request->getParsedBody());
        $this->assertSame(['a' => 1], $request->withParsedBody(['a' => 1])->getParsedBody());
        $this->assertInstanceOf(\stdClass::class, $request->withParsedBody(new \stdClass())->getParsedBody());
    }

    public function testWithParsedBodyRejectsScalar(): void
    {
        $request = $this->makeRequest();
        $withParsedBody = new \ReflectionMethod($request, 'withParsedBody');

        $this->expectException(\InvalidArgumentException::class);
        $withParsedBody->invoke($request, 42);
    }

    public function testAttributes(): void
    {
        $request = $this->makeRequest();

        $this->assertSame([], $request->getAttributes());
        $this->assertSame('fallback', $request->getAttribute('missing', 'fallback'));
        $this->assertNull($request->getAttribute('missing'));

        $withAttr = $request->withAttribute('user.id', 7);
        $this->assertSame(7, $withAttr->getAttribute('user.id'));
        $this->assertNull($request->getAttribute('user.id'), 'original unchanged');

        $without = $withAttr->withoutAttribute('user.id');
        $this->assertSame([], $without->getAttributes());
    }

    public function testBodyDefaultsToEmptyStream(): void
    {
        $request = $this->makeRequest();

        $this->assertSame('', (string) $request->getBody());
    }
}
