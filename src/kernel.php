<?php

declare(strict_types=1);

/*
 * ZEF FRAMEWORK v2.5.0-beta1 — MODULE DEFINITION TYPED-CONFIG RELEASE
 * --------------------------------------------------------
 * Single-file / zero-composer fallback bundle.
 * Implements PSR-11, PSR-7, PSR-15 and PSR-17 contracts.
 *
 * PHP >= 8.4
 *
 * The PSR interfaces below are conditional compatibility shims. If the
 * official PSR packages are already loaded, ZEF reuses them rather than
 * redeclaring the interfaces.
 */

/* ================================================================
 * SECTION 1 — PSR-11 COMPATIBILITY SHIM
 * ================================================================ */

namespace Psr\Container {
    if (!interface_exists(ContainerExceptionInterface::class)) {
        interface ContainerExceptionInterface extends \Throwable
        {
        }
    }
    if (!interface_exists(NotFoundExceptionInterface::class)) {
        interface NotFoundExceptionInterface extends ContainerExceptionInterface
        {
        }
    }
    if (!interface_exists(ContainerInterface::class)) {
        interface ContainerInterface
        {
            public function get(string $id): mixed;
            public function has(string $id): bool;
        }
    }
}

/* ================================================================
 * SECTION 1-B — PSR-3 COMPATIBILITY SHIM
 * ================================================================ */

namespace Psr\Log {
    if (!interface_exists(LoggerInterface::class)) {
        interface LoggerInterface
        {
            /** @param array<string, mixed> $context */
            public function emergency(string|\Stringable $message, array $context = []): void;
            /** @param array<string, mixed> $context */
            public function alert(string|\Stringable $message, array $context = []): void;
            /** @param array<string, mixed> $context */
            public function critical(string|\Stringable $message, array $context = []): void;
            /** @param array<string, mixed> $context */
            public function error(string|\Stringable $message, array $context = []): void;
            /** @param array<string, mixed> $context */
            public function warning(string|\Stringable $message, array $context = []): void;
            /** @param array<string, mixed> $context */
            public function notice(string|\Stringable $message, array $context = []): void;
            /** @param array<string, mixed> $context */
            public function info(string|\Stringable $message, array $context = []): void;
            /** @param array<string, mixed> $context */
            public function debug(string|\Stringable $message, array $context = []): void;
            /** @param mixed $level
             *  @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void;
        }
    }
    if (!class_exists(NullLogger::class)) {
        class NullLogger implements LoggerInterface
        {
            /** @param array<string, mixed> $context */
            #[\Override]
            public function emergency(string|\Stringable $message, array $context = []): void
            {
            }
            /** @param array<string, mixed> $context */
            #[\Override]
            public function alert(string|\Stringable $message, array $context = []): void
            {
            }
            /** @param array<string, mixed> $context */
            #[\Override]
            public function critical(string|\Stringable $message, array $context = []): void
            {
            }
            /** @param array<string, mixed> $context */
            #[\Override]
            public function error(string|\Stringable $message, array $context = []): void
            {
            }
            /** @param array<string, mixed> $context */
            #[\Override]
            public function warning(string|\Stringable $message, array $context = []): void
            {
            }
            /** @param array<string, mixed> $context */
            #[\Override]
            public function notice(string|\Stringable $message, array $context = []): void
            {
            }
            /** @param array<string, mixed> $context */
            #[\Override]
            public function info(string|\Stringable $message, array $context = []): void
            {
            }
            /** @param array<string, mixed> $context */
            #[\Override]
            public function debug(string|\Stringable $message, array $context = []): void
            {
            }
            /** @param mixed $level
             *  @param array<string, mixed> $context */
            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        }
    }
}

/* ================================================================
 * SECTION 1A — RELEASE VERSION AUTHORITY
 * ================================================================ */

namespace Zef\Framework\Foundation {
    final class ZefVersion
    {
        public const VERSION = '2.5.0-beta1';
    }
}

/* ================================================================
 * SECTION 1B — PSR-14 COMPATIBILITY SHIM
 * ================================================================ */

namespace Psr\EventDispatcher {
    if (!interface_exists(EventDispatcherInterface::class)) {
        interface EventDispatcherInterface
        {
            public function dispatch(object $event): object;
        }
    }

    if (!interface_exists(ListenerProviderInterface::class)) {
        interface ListenerProviderInterface
        {
            /** @return iterable<callable> */
            public function getListenersForEvent(object $event): iterable;
        }
    }

    if (!interface_exists(StoppableEventInterface::class)) {
        interface StoppableEventInterface
        {
            public function isPropagationStopped(): bool;
        }
    }
}

/* ================================================================
 * SECTION 2 — PSR-7 COMPATIBILITY SHIM
 * ================================================================ */

namespace Psr\Http\Message {
    if (!interface_exists(StreamInterface::class)) {
        interface StreamInterface
        {
            #[\Override]
            public function __toString(): string;
            public function close(): void;
            public function detach(): mixed;
            public function getSize(): ?int;
            public function tell(): int;
            public function eof(): bool;
            public function isSeekable(): bool;
            public function seek(int $offset, int $whence = SEEK_SET): void;
            public function rewind(): void;
            public function isWritable(): bool;
            public function write(string $string): int;
            public function isReadable(): bool;
            public function read(int $length): string;
            public function getContents(): string;
            public function getMetadata(?string $key = null): mixed;
        }
    }

    if (!interface_exists(MessageInterface::class)) {
        interface MessageInterface
        {
            public function getProtocolVersion(): string;
            public function withProtocolVersion(string $version): MessageInterface;
            /** @return array<string, list<string>> */
            public function getHeaders(): array;
            public function hasHeader(string $name): bool;
            /** @return list<string> */
            public function getHeader(string $name): array;
            public function getHeaderLine(string $name): string;
            /** @param string|list<string> $value */
            public function withHeader(string $name, $value): MessageInterface;
            /** @param string|list<string> $value */
            public function withAddedHeader(string $name, $value): MessageInterface;
            public function withoutHeader(string $name): MessageInterface;
            public function getBody(): StreamInterface;
            public function withBody(StreamInterface $body): MessageInterface;
        }
    }

    if (!interface_exists(UriInterface::class)) {
        interface UriInterface
        {
            public function getScheme(): string;
            public function getAuthority(): string;
            public function getUserInfo(): string;
            public function getHost(): string;
            public function getPort(): ?int;
            public function getPath(): string;
            public function getQuery(): string;
            public function getFragment(): string;
            public function withScheme(string $scheme): UriInterface;
            public function withUserInfo(string $user, ?string $password = null): UriInterface;
            public function withHost(string $host): UriInterface;
            public function withPort(?int $port): UriInterface;
            public function withPath(string $path): UriInterface;
            public function withQuery(string $query): UriInterface;
            public function withFragment(string $fragment): UriInterface;
            #[\Override]
            public function __toString(): string;
        }
    }

    if (!interface_exists(RequestInterface::class)) {
        interface RequestInterface extends MessageInterface
        {
            public function getRequestTarget(): string;
            public function withRequestTarget(string $requestTarget): RequestInterface;
            public function getMethod(): string;
            public function withMethod(string $method): RequestInterface;
            public function getUri(): UriInterface;
            public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface;
        }
    }

    if (!interface_exists(ServerRequestInterface::class)) {
        interface ServerRequestInterface extends RequestInterface
        {
            /** @return array<string, mixed> */
            public function getServerParams(): array;
            /** @return array<string, mixed> */
            public function getCookieParams(): array;
            /** @param array<string, mixed> $cookies */
            public function withCookieParams(array $cookies): ServerRequestInterface;
            /** @return array<string, mixed> */
            public function getQueryParams(): array;
            /** @param array<string, mixed> $query */
            public function withQueryParams(array $query): ServerRequestInterface;
            /** @return array<string, mixed> */
            public function getUploadedFiles(): array;
            /** @param array<string, mixed> $uploadedFiles */
            public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface;
            public function getParsedBody(): mixed;
            /** @param mixed $data */
            public function withParsedBody($data): ServerRequestInterface;
            /** @return array<string, mixed> */
            public function getAttributes(): array;
            public function getAttribute(string $name, mixed $default = null): mixed;
            public function withAttribute(string $name, mixed $value): ServerRequestInterface;
            public function withoutAttribute(string $name): ServerRequestInterface;
        }
    }

    if (!interface_exists(ResponseInterface::class)) {
        interface ResponseInterface extends MessageInterface
        {
            public function getStatusCode(): int;
            public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface;
            public function getReasonPhrase(): string;
        }
    }

    if (!interface_exists(UploadedFileInterface::class)) {
        interface UploadedFileInterface
        {
            public function getStream(): StreamInterface;
            public function moveTo(string $targetPath): void;
            public function getSize(): ?int;
            public function getError(): int;
            public function getClientFilename(): ?string;
            public function getClientMediaType(): ?string;
        }
    }
}

/* ================================================================
 * SECTION 3 — PSR-15 COMPATIBILITY SHIM
 * ================================================================ */

namespace Psr\Http\Server {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;

    if (!interface_exists(RequestHandlerInterface::class)) {
        interface RequestHandlerInterface
        {
            public function handle(ServerRequestInterface $request): ResponseInterface;
        }
    }

    if (!interface_exists(MiddlewareInterface::class)) {
        interface MiddlewareInterface
        {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface;
        }
    }
}

/* ================================================================
 * SECTION 4 — PSR-17 COMPATIBILITY SHIM
 * ================================================================ */

namespace Psr\Http\Message {
    if (!interface_exists(RequestFactoryInterface::class)) {
        interface RequestFactoryInterface
        {
            /** @param string|UriInterface $uri */
            public function createRequest(string $method, $uri): RequestInterface;
        }
    }
    if (!interface_exists(ResponseFactoryInterface::class)) {
        interface ResponseFactoryInterface
        {
            public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface;
        }
    }
    if (!interface_exists(ServerRequestFactoryInterface::class)) {
        interface ServerRequestFactoryInterface
        {
            /** @param string|UriInterface $uri
             *  @param array<string, mixed> $serverParams */
            public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface;
        }
    }
    if (!interface_exists(StreamFactoryInterface::class)) {
        interface StreamFactoryInterface
        {
            public function createStream(string $content = ''): StreamInterface;
            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface;
            /** @param resource $resource */
            public function createStreamFromResource($resource): StreamInterface;
        }
    }
    if (!interface_exists(UploadedFileFactoryInterface::class)) {
        interface UploadedFileFactoryInterface
        {
            public function createUploadedFile(
                StreamInterface $stream,
                ?int $size = null,
                int $error = UPLOAD_ERR_OK,
                ?string $clientFilename = null,
                ?string $clientMediaType = null,
            ): UploadedFileInterface;
        }
    }
    if (!interface_exists(UriFactoryInterface::class)) {
        interface UriFactoryInterface
        {
            public function createUri(string $uri = ''): UriInterface;
        }
    }
}

/* ================================================================
 * SECTION 5 — FRAMEWORK EXCEPTIONS
 * ================================================================ */


/* ================================================================
 * SECTION 6 — VALIDATORS / SECURITY PRIMITIVES
 * ================================================================ */


/* ================================================================
 * SECTION 7 — CONSTANTS
 * ================================================================ */


/* ================================================================
 * SECTION 8 — HTTP MESSAGE IMPLEMENTATIONS
 * ================================================================ */


/* ================================================================
 * SECTION 9 — PSR-17 FACTORIES
 * ================================================================ */


/* ================================================================
 * SECTION 10 — CONFIGURATION
 * ================================================================ */


/* ================================================================
 * SECTION 10.5 — ARCHITECTURE POLICY
 * ================================================================ */


/* ================================================================
 * SECTION 11 — DI CONTAINER WITH LIFETIMES
 * ================================================================ */


/* ================================================================
 * SECTION 12 — ROUTER (extracted to src/Framework/Router/Router.php)
 * ================================================================ */

/* ================================================================
 * SECTION 14 — REQUEST FACTORY FROM PHP GLOBALS
 * ================================================================ */


/* ================================================================
 * SECTION 16 — SAMPLE CORE MODULE
 * ================================================================ */


/* ================================================================
 * SECTION 17 — SAMPLE STORE PLUGIN
 * ================================================================ */


/* ================================================================
 * SECTION 18 — APPLICATION BOOTSTRAP
 * ================================================================
 */


/* ================================================================
 * SECTION 19 — SELF TEST
 * ================================================================ */

namespace {
    $scriptFile = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if (is_string($scriptFile) && realpath($scriptFile) === __FILE__) {
        if (PHP_VERSION_ID < 80400) { // @phpstan-ignore smaller.alwaysFalse (fail-fast guard: this bundle is executed directly on unsupported PHP)
            fwrite(STDERR, 'ZEF Framework v' . \Zef\Framework\Foundation\ZefVersion::VERSION . " requires PHP >= 8.4\n");
            exit(1);
        }
        $debug = filter_var(getenv('ZEF_DEBUG') ?: '0', FILTER_VALIDATE_BOOL);
        if (PHP_SAPI === 'cli') {
            $args = $_SERVER['argv'] ?? [];
            if (is_array($args) && in_array('--self-test', $args, true)) {
                exit((new \Zef\Test\CliRunner())->run(false));
            }
            fwrite(STDOUT, 'ZEF Framework v' . \Zef\Framework\Foundation\ZefVersion::VERSION . "\nRun with --self-test for diagnostics.\n");
            exit(0);
        }
        try {
            $app = \Zef\App\Bootstrap::createApp($debug);
            $response = $app->handleGlobals();
            $app->emit($response);
        } catch (\Throwable $e) {
            if (!headers_sent()) {
                http_response_code(500);
            }
            header('Content-Type: text/plain; charset=utf-8');
            echo $debug ? get_class($e) . ': ' . $e->getMessage() : 'Internal Server Error';
        }
    }
}
