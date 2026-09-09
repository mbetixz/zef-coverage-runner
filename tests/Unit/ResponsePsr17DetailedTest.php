<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\InvalidHeaderException;
use Zef\Framework\Http\Psr17Factory;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\UploadedFile;
use Zef\Framework\Http\Uri;

final class ResponsePsr17DetailedTest extends TestCase
{
    public function testResponseDefaults(): void
    {
        $response = new Response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getReasonPhrase());
        $this->assertSame('', (string) $response->getBody());
    }

    public function testResponseConstructorWithAllArguments(): void
    {
        $response = new Response(
            status: 201,
            headers: ['X-Tag' => 'created'],
            body: 'payload',
            reasonPhrase: 'Made',
            protocolVersion: '1.0',
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Made', $response->getReasonPhrase());
        $this->assertSame('payload', (string) $response->getBody());
        $this->assertSame('1.0', $response->getProtocolVersion());
        $this->assertSame('created', $response->getHeaderLine('X-Tag'));
    }

    public function testConstructorWithStreamBody(): void
    {
        $stream = Stream::fromString('binary');
        $response = new Response(200, [], $stream);

        $this->assertSame($stream, $response->getBody());
    }

    public function testKnownStatusUsesCanonicalReasonPhrase(): void
    {
        $cases = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            413 => 'Content Too Large',
            418 => "I'm a teapot",
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];

        foreach ($cases as $code => $phrase) {
            $response = new Response($code);
            $this->assertSame($phrase, $response->getReasonPhrase(), "status {$code}");
        }
    }

    public function testUnknownStatusFallsBackToEmptyReasonPhrase(): void
    {
        $response = new Response(299);

        $this->assertSame(299, $response->getStatusCode());
        $this->assertSame('', $response->getReasonPhrase());
    }

    public function testConstructorRejectsInvalidStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response(99);
    }

    public function testConstructorRejectsStatusOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response(600);
    }

    public function testWithStatusClonesAndUpdatesPhrase(): void
    {
        $response = new Response(200);
        $updated = $response->withStatus(404);

        $this->assertNotSame($response, $updated);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getReasonPhrase());
        $this->assertSame(404, $updated->getStatusCode());
        $this->assertSame('Not Found', $updated->getReasonPhrase());
    }

    public function testWithStatusCustomReasonPhraseWins(): void
    {
        $response = new Response(200);
        $updated = $response->withStatus(418, 'Custom Teapot');

        $this->assertSame(418, $updated->getStatusCode());
        $this->assertSame('Custom Teapot', $updated->getReasonPhrase());
    }

    public function testWithStatusKeepsHeadersAndBody(): void
    {
        $response = new Response(200, ['X-Keep' => 'yes'], 'body');
        $updated = $response->withStatus(201);

        $this->assertSame('yes', $updated->getHeaderLine('X-Keep'));
        $this->assertSame('body', (string) $updated->getBody());
    }

    public function testWithStatusRejectsInvalidCode(): void
    {
        $response = new Response(200);

        $this->expectException(\InvalidArgumentException::class);
        $response->withStatus(1000);
    }

    public function testPsr17CreateResponse(): void
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(202, 'Accepted-ish');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('Accepted-ish', $response->getReasonPhrase());
    }

    public function testPsr17CreateResponseDefaultPhrase(): void
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(204);

        $this->assertSame('No Content', $response->getReasonPhrase());
    }

    public function testPsr17CreateRequestAcceptsUriObjectAndString(): void
    {
        $factory = new Psr17Factory();
        $fromObject = $factory->createRequest('POST', new Uri('https://x.example/a'));
        $fromString = $factory->createRequest('post', 'https://x.example/b');

        $this->assertSame('POST', $fromObject->getMethod());
        $this->assertSame('x.example', $fromString->getUri()->getHost());
        $this->assertSame('/b', $fromString->getUri()->getPath());
    }

    public function testPsr17CreateServerRequest(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', 'https://s.example/p', ['k' => 'v']);

        $this->assertSame('s.example', $request->getUri()->getHost());
        $this->assertSame('v', $request->getServerParams()['k']);
    }

    public function testPsr17CreateStream(): void
    {
        $factory = new Psr17Factory();
        $stream = $factory->createStream('abc');

        $this->assertInstanceOf(Stream::class, $stream);
        $this->assertSame('abc', $stream->__toString());
    }

    public function testPsr17CreateStreamFromFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'zef-psr17-');
        $this->assertNotFalse($file);
        file_put_contents($file, 'file-data');

        try {
            $factory = new Psr17Factory();
            $stream = $factory->createStreamFromFile($file, 'rb');

            $this->assertSame('file-data', $stream->__toString());
        } finally {
            @unlink($file);
        }
    }

    public function testPsr17CreateStreamFromFileEmptyFilenameThrows(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(\InvalidArgumentException::class);
        $factory->createStreamFromFile('');
    }

    public function testPsr17CreateStreamFromFileInvalidModeThrows(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(\InvalidArgumentException::class);
        $factory->createStreamFromFile('/tmp/nonexistent-file', 'q');
    }

    public function testPsr17CreateStreamFromFileMissingFileThrows(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(\RuntimeException::class);
        $factory->createStreamFromFile('/tmp/definitely-missing-' . bin2hex(random_bytes(6)));
    }

    public function testPsr17CreateStreamFromResource(): void
    {
        $factory = new Psr17Factory();
        $handle = fopen('php://memory', 'rb+');
        $this->assertNotFalse($handle);
        fwrite($handle, 'resource-data');
        rewind($handle);

        $stream = $factory->createStreamFromResource($handle);
        $this->assertSame('resource-data', $stream->__toString());
        fclose($handle);
    }

    public function testPsr17CreateStreamFromResourceRejectsNonResource(): void
    {
        $factory = new Psr17Factory();

        // Invoke through reflection so the real is_resource() guard inside
        // createStreamFromResource() is exercised with a non-resource value.
        $method = new \ReflectionMethod(Psr17Factory::class, 'createStreamFromResource');

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($factory, 'not-a-resource');
    }

    public function testPsr17CreateStreamFromResourceRejectsUnreadableMode(): void
    {
        $factory = new Psr17Factory();
        $path = tempnam(sys_get_temp_dir(), 'zef-psr17w-');
        $this->assertNotFalse($path);
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $factory->createStreamFromResource($handle);
        } finally {
            fclose($handle);
            @unlink($path);
        }
    }

    public function testPsr17CreateUploadedFile(): void
    {
        $factory = new Psr17Factory();
        $stream = Stream::fromString('up');

        $uploaded = $factory->createUploadedFile($stream, 2, UPLOAD_ERR_OK, 'a.txt', 'text/plain');

        $this->assertInstanceOf(UploadedFile::class, $uploaded);
        $this->assertSame(2, $uploaded->getSize());
        $this->assertSame('a.txt', $uploaded->getClientFilename());
        $this->assertSame('text/plain', $uploaded->getClientMediaType());
    }

    public function testPsr17CreateUploadedFileDefaultsSizeFromStream(): void
    {
        $factory = new Psr17Factory();
        $uploaded = $factory->createUploadedFile(Stream::fromString('12345'));

        $this->assertSame(5, $uploaded->getSize());
    }

    public function testPsr17CreateUploadedFileRejectsNegativeSize(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(\InvalidArgumentException::class);
        $factory->createUploadedFile(Stream::fromString('x'), -1);
    }

    public function testPsr17CreateUploadedFileRejectsUnreadableStream(): void
    {
        $factory = new Psr17Factory();
        $path = tempnam(sys_get_temp_dir(), 'zef-up-');
        $this->assertNotFalse($path);
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $factory->createUploadedFile(new Stream($handle));
        } finally {
            fclose($handle);
            @unlink($path);
        }
    }

    public function testPsr17CreateUri(): void
    {
        $factory = new Psr17Factory();
        $uri = $factory->createUri('https://u.example/x');

        $this->assertInstanceOf(Uri::class, $uri);
        $this->assertSame('u.example', $uri->getHost());
    }

    public function testResponseRejectsHeaderInjectionOnConstruction(): void
    {
        $this->expectException(InvalidHeaderException::class);
        new Response(200, ["X-Evil: ok\r\nInjected: 1" => 'x']);
    }
}
