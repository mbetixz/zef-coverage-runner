<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Zef\Framework\Exception\InvalidHeaderException;
use Zef\Framework\Http\MessageBase;
use Zef\Framework\Http\Request;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\Uri;

final class MessageBaseDetailedTest extends TestCase
{
    public function testConstructorRejectsMalformedProtocolVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP protocol version');
        new Response(200, [], '', '', '1.x');
    }

    public function testConstructorSeedsHeadersWithReplaceSemantics(): void
    {
        $response = new Response(200, ['X-Foo' => 'a', 'x-foo' => 'b']);

        // Case-insensitive replace: only one header survives with the latest casing.
        $this->assertSame(['b'], $response->getHeader('X-FOO'));
        $this->assertSame(['x-foo'], array_keys($response->getHeaders()));
    }

    public function testConstructorNormalizesArrayHeaderValues(): void
    {
        $response = new Response(200, ['X-Multi' => ['a', '2', 'c']]);

        $this->assertSame(['a', '2', 'c'], $response->getHeader('X-Multi'));
        $this->assertSame('a, 2, c', $response->getHeaderLine('X-Multi'));
    }

    public function testWithProtocolVersionClonesAndValidates(): void
    {
        $response = new Response(200);
        $clone = $response->withProtocolVersion('2.0');

        $this->assertNotSame($response, $clone);
        $this->assertSame('1.1', $response->getProtocolVersion());
        $this->assertSame('2.0', $clone->getProtocolVersion());

        $this->expectException(\InvalidArgumentException::class);
        $response->withProtocolVersion('two');
    }

    public function testWithHeaderReplacesSameNameCaseInsensitively(): void
    {
        $response = new Response(200, ['X-Test' => 'first']);

        $updated = $response
            ->withHeader('x-test', 'second')
            ->withHeader('X-Test', ['third', 'fourth']);

        $this->assertSame(['third', 'fourth'], $updated->getHeader('X-TEST'));
        $this->assertSame(['X-Test'], array_keys($updated->getHeaders()));
        $this->assertSame('third, fourth', $updated->getHeaderLine('x-test'));
    }

    public function testWithAddedHeaderAppendsWhenExisting(): void
    {
        $response = new Response(200, ['X-List' => 'one']);

        $updated = $response->withAddedHeader('x-list', 'two');
        $this->assertSame(['one', 'two'], $updated->getHeader('X-LIST'));

        $created = (new Response(200))->withAddedHeader('X-New', 'solo');
        $this->assertSame(['solo'], $created->getHeader('x-new'));
        $this->assertTrue($created->hasHeader('X-NEW'));
    }

    public function testWithoutHeaderRemovesAllValuesIgnoringCase(): void
    {
        $response = new Response(200, ['X-Drop' => ['a', 'b'], 'X-Keep' => 'c']);

        $updated = $response->withoutHeader('x-drop');
        $this->assertFalse($updated->hasHeader('X-Drop'));
        $this->assertSame(['c'], $updated->getHeader('X-Keep'));
        $this->assertTrue($response->hasHeader('X-Drop')); // immutability
    }

    public function testHasHeaderAndGetHeaderCaseInsensitiveLookup(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/plain']);

        $this->assertTrue($response->hasHeader('content-type'));
        $this->assertTrue($response->hasHeader('CONTENT-TYPE'));
        $this->assertFalse($response->hasHeader('missing'));
        $this->assertSame([], $response->getHeader('missing'));
        $this->assertSame('', $response->getHeaderLine('missing'));
    }

    public function testWithHeaderRejectsInvalidName(): void
    {
        $response = new Response(200);

        $this->expectException(InvalidHeaderException::class);
        $response->withHeader("X-Bad\nName", 'value');
    }

    public function testWithHeaderRejectsCrLfInjectionInValue(): void
    {
        $response = new Response(200);

        $this->expectException(InvalidHeaderException::class);
        $response->withHeader('X-Location', "/ok\r\nSet-Cookie: evil=1");
    }

    public function testWithAddedHeaderValidatesBeforeMutation(): void
    {
        $response = new Response(200, ['X-Safe' => 'a']);

        try {
            $response->withAddedHeader('x-safe', "bad\r\nInjected: 1");
            $this->fail('Expected InvalidHeaderException.');
        } catch (InvalidHeaderException) {
            $this->assertSame(['a'], $response->getHeader('X-Safe'));
        }
    }

    public function testWithHeaderKeepsOtherHeadersIntact(): void
    {
        $response = new Response(200, ['X-A' => '1', 'X-B' => '2']);

        $updated = $response->withHeader('X-C', '3');
        $this->assertSame(['1', '2', '3'], array_values(array_map(
            static fn (array $h): string => $h[0],
            $updated->getHeaders(),
        )));
    }

    public function testWithBodyClonesAndReplacesStream(): void
    {
        $response = new Response(200, [], 'original');
        $newBody = Stream::fromString('replacement');

        $updated = $response->withBody($newBody);

        $this->assertNotSame($response, $updated);
        $this->assertSame('original', $response->getBody()->__toString());
        $this->assertSame('replacement', $updated->getBody()->__toString());
        $this->assertSame($newBody, $updated->getBody());
    }

    public function testBodyStringRewindsAndRestoresPosition(): void
    {
        $body = Stream::fromString('hello world');
        $body->seek(6);
        $message = new Response(200, [], $body);

        $this->assertSame('hello world', $message->bodyString());
        $this->assertSame(6, $body->tell()); // pointer restored
    }

    public function testBodyStringOnEmptyStreamReturnsEmpty(): void
    {
        $message = new Response(200);
        $this->assertSame('', $message->bodyString());
    }

    public function testBodyStringDegradesToEmptyOnUnseekableFailure(): void
    {
        // Non-seekable, non-readable stream -> bodyString() must not throw.
        $unreadable = $this->createMock(StreamInterface::class);
        $unreadable->method('isSeekable')->willReturn(false);
        $unreadable->method('rewind')->willThrowException(new \RuntimeException('not readable'));

        $message = new Response(200, [], $unreadable);
        $this->assertSame('', $message->bodyString());
    }

    public function testRequestDerivesHostHeaderFromUriAtConstruction(): void
    {
        $request = new Request('GET', new Uri('https://api.example.com:8443/x'));

        $this->assertSame(['api.example.com:8443'], $request->getHeader('host'));
        $this->assertTrue($request->hasHeader('Host'));
    }

    public function testRequestWithUriPreserveHostTrueKeepsExistingHost(): void
    {
        $request = new Request('GET', new Uri('https://a.example.com/'));
        $request = $request->withHeader('Host', 'custom.example.com');

        $updated = $request->withUri(new Uri('https://b.example.com/'), true);
        $this->assertSame('custom.example.com', $updated->getHeaderLine('Host'));
    }

    public function testRequestWithUriPreserveHostFalseReplacesHost(): void
    {
        $request = new Request('GET', new Uri('https://a.example.com/'), ['Host' => 'old.example.com']);

        $updated = $request->withUri(new Uri('http://b.example.com:8080/'), false);
        $this->assertSame('b.example.com:8080', $updated->getHeaderLine('Host'));
    }

    public function testRequestWithUriToHostlessUriKeepsExistingHostWhenPreserved(): void
    {
        $request = new Request('GET', new Uri('https://a.example.com/'));
        $updated = $request->withUri(new Uri('/relative'), true);

        $this->assertSame('a.example.com', $updated->getHeaderLine('Host'));
    }
}
