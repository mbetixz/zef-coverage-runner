<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\UploadedFileInterface;
use Zef\Framework\Http\Psr17Factory;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\UploadedFile;
use Zef\Framework\Http\Uri;

$pass = 0;
$fail = 0;
$check = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    if ($condition) {
        ++$pass;
        echo "PASS: {$message}\n";
        return;
    }
    ++$fail;
    echo "FAIL: {$message}\n";
};
$throws = static function (string $class, callable $operation): bool {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception instanceof $class;
    }
    return false;
};
$implements = static function (string $class, string $interface): bool {
    /** @var class-string $class */
    return (new ReflectionClass($class))->implementsInterface($interface);
};
$isInstance = static function (mixed $value, string $class): bool {
    return $value instanceof $class;
};

$check($implements(Stream::class, StreamInterface::class), 'Stream implements PSR-7 stream');
$check($implements(Uri::class, UriInterface::class), 'Uri implements PSR-7 URI');
$check($implements(Response::class, ResponseInterface::class), 'Response implements PSR-7 response');
$check($implements(ServerRequest::class, ServerRequestInterface::class), 'ServerRequest implements PSR-7 server request');
$check($implements(UploadedFile::class, UploadedFileInterface::class), 'UploadedFile implements PSR-7 uploaded file');

$factory = new Psr17Factory();
$stream = $factory->createStream('alpha');
$check($stream->__toString() === 'alpha', 'stream string round-trip');
$check($stream->getSize() === 5, 'stream size is reported');
$check($throws(InvalidArgumentException::class, static fn() => $stream->read(-1)), 'negative stream length rejected');
$stream->close();
$check($stream->__toString() === '' && $stream->getSize() === null, 'closed stream is empty and detached-sized');

$uri = $factory->createUri('HTTP://Example.COM:8080/a path?q=hello world#frag');
$check($uri->getScheme() === 'http', 'URI scheme normalized');
$check($uri->getHost() === 'example.com' && $uri->getPort() === 8080, 'URI authority normalized');
$check((string) $uri === 'http://example.com:8080/a%20path?q=hello%20world#frag', 'URI components encoded deterministically');
$check((string) $uri->withPath('/next path') === 'http://example.com:8080/next%20path?q=hello%20world#frag', 'URI withPath is immutable');
$check($throws(InvalidArgumentException::class, static fn() => $factory->createUri("http://bad\n.example")), 'URI control characters rejected');

$response = $factory->createResponse(201)->withHeader('X-Test', 'one');
$check($response->getStatusCode() === 201 && $response->getReasonPhrase() === 'Created', 'response status and reason preserved');
$response2 = $response->withHeader('x-test', 'two')->withAddedHeader('X-TEST', 'three');
$check($response2->getHeaderLine('X-Test') === 'two, three', 'header replacement and append are case-insensitive');
$check(count($response2->getHeaders()) === 1, 'header dictionary does not duplicate casing');
$check($response2->withoutHeader('x-test')->getHeaders() === [], 'header removal is immutable');
$check((string) $response->getBody() === '', 'response default body is empty');
$check($throws(InvalidArgumentException::class, static fn() => $factory->createResponse(99)), 'invalid response status rejected');

$request = $factory->createServerRequest('get', 'http://localhost/demo?x=1', ['REMOTE_ADDR' => '127.0.0.1']);
$check($request->getMethod() === 'GET', 'request method normalized');
$check($request->getRequestTarget() === '/demo?x=1', 'request target derived from URI');
$check($request->getHeaderLine('Host') === 'localhost', 'host header derived from URI');
$request2 = $request->withAttribute('trace', 'abc')->withParsedBody(['id' => 7]);
$check($request->getAttribute('trace') === null && $request2->getAttribute('trace') === 'abc', 'request attributes are immutable');
$check($request2->getParsedBody() === ['id' => 7], 'parsed body accepts arrays');
$scalar = 'scalar';
$check($throws(InvalidArgumentException::class, static function () use ($request, $scalar): bool {
    /** @var mixed $value */
    $value = $scalar;
    (new ReflectionMethod($request, 'withParsedBody'))->invoke($request, $value);
    return true;
}), 'scalar parsed body rejected');
$check($throws(InvalidArgumentException::class, static fn() => $factory->createServerRequest('bad method', '/')), 'invalid request method rejected');

$uploaded = new UploadedFile($factory->createStream('upload'), null, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
$check($uploaded->getSize() === 6 && $uploaded->getClientFilename() === 'a.txt', 'uploaded file metadata preserved');
$check((string) $uploaded->getStream() === 'upload', 'uploaded file stream available');
$check($throws(InvalidArgumentException::class, static fn() => $uploaded->moveTo('')), 'empty upload destination rejected');
$failedUpload = new UploadedFile($factory->createStream(''), null, UPLOAD_ERR_NO_FILE);
$check($throws(RuntimeException::class, static fn() => $failedUpload->getStream()), 'failed upload stream rejected');

$check($isInstance($factory->createResponse(), ResponseInterface::class), 'PSR-17 response factory returns response');
$check($isInstance($factory->createServerRequest('POST', '/')->withBody($factory->createStream('x')), ServerRequestInterface::class), 'PSR-17 request factory returns request');
$resource = fopen('php://temp', 'r+');
$check($resource !== false && $isInstance($factory->createStreamFromResource($resource), StreamInterface::class), 'PSR-17 resource factory returns stream');

printf("HttpMessages characterization: %d pass, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
