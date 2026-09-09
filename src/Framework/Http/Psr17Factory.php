<?php

declare(strict_types=1);

namespace Zef\Framework\Http {
    use Psr\Http\Message\RequestFactoryInterface;
    use Psr\Http\Message\RequestInterface;
    use Psr\Http\Message\ResponseFactoryInterface;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestFactoryInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Message\StreamFactoryInterface;
    use Psr\Http\Message\StreamInterface;
    use Psr\Http\Message\UploadedFileFactoryInterface;
    use Psr\Http\Message\UploadedFileInterface;
    use Psr\Http\Message\UriFactoryInterface;
    use Psr\Http\Message\UriInterface;

    final class Psr17Factory implements RequestFactoryInterface, ResponseFactoryInterface, ServerRequestFactoryInterface, StreamFactoryInterface, UploadedFileFactoryInterface, UriFactoryInterface
    {
        /** @param string|UriInterface $uri */
        #[\Override]
        public function createRequest(string $method, $uri): RequestInterface
        {
            $u = $uri instanceof UriInterface ? $uri : new Uri((string) $uri);
            return new Request($method, $u);
        }
        #[\Override]
        public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
        {
            return new Response($code, reasonPhrase:$reasonPhrase);
        }
        /**
         * @param string|UriInterface   $uri
         * @param array<string, mixed>  $serverParams
         */
        #[\Override]
        public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
        {
            $u = $uri instanceof UriInterface ? $uri : new Uri((string) $uri);
            return new ServerRequest($method, $u, $serverParams);
        }
        #[\Override]
        public function createStream(string $content = ''): StreamInterface
        {
            return Stream::fromString($content);
        }
        /**
         * @throws \InvalidArgumentException When the filename is empty or the mode is invalid.
         * @throws \RuntimeException          When the file cannot be opened.
         */
        #[\Override]
        public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
        {
            if ($filename === '') {
                throw new \InvalidArgumentException('Filename must not be empty.');
            } if (preg_match('/^[rwaxtc](?:[bt])?(?:\+)?e?\z/', $mode) !== 1 && preg_match('/^[rwaxtc](?:\+)?[bt]e?\z/', $mode) !== 1) {
                throw new \InvalidArgumentException("Invalid stream mode '{$mode}'.");
            } $warning = null;
            set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
                $warning = $message;
                return true;
            });
            try {
                $h = fopen($filename, $mode);
            } finally {
                restore_error_handler();
            } if ($h === false) {
                throw new \RuntimeException("Unable to open '{$filename}'" . ($warning !== null ? ": {$warning}" : '.'));
            } return new Stream($h);
        }
        /**
         * @param resource $resource
         *
         * @throws \InvalidArgumentException When $resource is not a resource or is not readable.
         */
        #[\Override]
        public function createStreamFromResource($resource): StreamInterface
        {
            if (!is_resource($resource)) {
                throw new \InvalidArgumentException('Resource required.');
            }
            $meta = stream_get_meta_data($resource);
            $mode = $meta['mode'];
            if ($mode === '' || preg_match('/[r+]/', $mode) !== 1) {
                throw new \InvalidArgumentException('Resource must be readable.');
            } return new Stream($resource);
        }
        #[\Override]
        public function createUploadedFile(StreamInterface $stream, ?int $size = null, int $error = UPLOAD_ERR_OK, ?string $clientFilename = null, ?string $clientMediaType = null): UploadedFileInterface
        {
            if (!$stream->isReadable()) {
                throw new \InvalidArgumentException('Uploaded file stream must be readable.');
            } if ($size !== null && $size < 0) {
                throw new \InvalidArgumentException('Uploaded file size must not be negative.');
            } return new UploadedFile($stream, $size ?? $stream->getSize(), $error, $clientFilename, $clientMediaType);
        }
        #[\Override]
        public function createUri(string $uri = ''): UriInterface
        {
            return new Uri($uri);
        }
    }
}
