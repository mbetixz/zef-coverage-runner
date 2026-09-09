<?php
declare(strict_types=1);

/*
 * ZEF FRAMEWORK v2.5.0-alpha4 — PSR-14 ZERO-ADAPTER GATE RELEASE
 * --------------------------------------------------------
 * Single-file / zero-composer fallback bundle.
 * Implements PSR-11, PSR-7, PSR-15 and PSR-17 contracts.
 *
 * PHP >= 8.1
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
        interface ContainerExceptionInterface extends \Throwable {}
    }
    if (!interface_exists(NotFoundExceptionInterface::class)) {
        interface NotFoundExceptionInterface extends ContainerExceptionInterface {}
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
            public function emergency(string|\Stringable $message, array $context = []): void;
            public function alert(string|\Stringable $message, array $context = []): void;
            public function critical(string|\Stringable $message, array $context = []): void;
            public function error(string|\Stringable $message, array $context = []): void;
            public function warning(string|\Stringable $message, array $context = []): void;
            public function notice(string|\Stringable $message, array $context = []): void;
            public function info(string|\Stringable $message, array $context = []): void;
            public function debug(string|\Stringable $message, array $context = []): void;
            public function log($level, string|\Stringable $message, array $context = []): void;
        }
    }
    if (!class_exists(NullLogger::class)) {
        class NullLogger implements LoggerInterface
        {
            public function emergency(string|\Stringable $message, array $context = []): void {}
            public function alert(string|\Stringable $message, array $context = []): void {}
            public function critical(string|\Stringable $message, array $context = []): void {}
            public function error(string|\Stringable $message, array $context = []): void {}
            public function warning(string|\Stringable $message, array $context = []): void {}
            public function notice(string|\Stringable $message, array $context = []): void {}
            public function info(string|\Stringable $message, array $context = []): void {}
            public function debug(string|\Stringable $message, array $context = []): void {}
            public function log($level, string|\Stringable $message, array $context = []): void {}
        }
    }
}

/* ================================================================
 * SECTION 1A — RELEASE VERSION AUTHORITY
 * ================================================================ */
namespace Zef\Framework\Foundation {
    final class ZefVersion
    {
        public const VERSION = '2.5.0-alpha4';
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
            public function getHeaders(): array;
            public function hasHeader(string $name): bool;
            public function getHeader(string $name): array;
            public function getHeaderLine(string $name): string;
            public function withHeader(string $name, $value): MessageInterface;
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
            public function getServerParams(): array;
            public function getCookieParams(): array;
            public function withCookieParams(array $cookies): ServerRequestInterface;
            public function getQueryParams(): array;
            public function withQueryParams(array $query): ServerRequestInterface;
            public function getUploadedFiles(): array;
            public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface;
            public function getParsedBody(): mixed;
            public function withParsedBody($data): ServerRequestInterface;
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
                RequestHandlerInterface $handler
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
            public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface;
        }
    }
    if (!interface_exists(StreamFactoryInterface::class)) {
        interface StreamFactoryInterface
        {
            public function createStream(string $content = ''): StreamInterface;
            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface;
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
                ?string $clientMediaType = null
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
namespace Zef\Framework\Exception {
    use Psr\Container\ContainerExceptionInterface;
    use Psr\Container\NotFoundExceptionInterface;

    final class ServiceNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
    {
        public function __construct(
            public readonly string $serviceId,
            public readonly ?string $module = null
        ) {
            $suffix = $module !== null ? " (module: '{$module}')" : '';
            parent::__construct("Service '{$serviceId}' not found{$suffix}.");
        }
    }

    final class ServiceResolutionException extends \RuntimeException implements ContainerExceptionInterface
    {
        public function __construct(string $id, string $reason, ?\Throwable $previous = null)
        {
            parent::__construct("Cannot resolve service '{$id}': {$reason}", 0, $previous);
        }
    }

    final class ServiceCircularDependencyException extends \RuntimeException implements ContainerExceptionInterface
    {
        public function __construct(public readonly array $chain)
        {
            parent::__construct('Circular service dependency detected: ' . implode(' -> ', $chain));
        }

        public function getChain(): array { return $this->chain; }
    }

    final class InvalidFactoryException extends \RuntimeException implements ContainerExceptionInterface {}

    final class CircularAliasException extends \RuntimeException
    {
        public function __construct(public readonly array $chain)
        {
            parent::__construct('Circular alias detected: ' . implode(' -> ', $chain));
        }
    }

    final class InvalidConfigurationException extends \RuntimeException {}
    final class ModuleDependencyViolationException extends \RuntimeException {}

    final class RouteNotFoundException extends \RuntimeException
    {
        public function __construct(public readonly string $method, public readonly string $path)
        {
            parent::__construct("No route matched [{$method}] {$path}.");
        }
    }

    final class MethodNotAllowedException extends \RuntimeException
    {
        public function __construct(public readonly string $method, public readonly string $path, public readonly array $allowedMethods)
        {
            parent::__construct("Method '{$method}' is not allowed for {$path}.");
        }
    }

    final class PayloadTooLargeException extends \RuntimeException {}

    final class RouteConstraintException extends \RuntimeException
    {
        public function __construct(
            public readonly string $param,
            public readonly string $type,
            public readonly string $value
        ) {
            parent::__construct("Route param '{$param}' failed constraint '{$type}'.");
        }
    }

    final class InvalidHeaderException extends \InvalidArgumentException {}
}

/* ================================================================
 * SECTION 6 — VALIDATORS / SECURITY PRIMITIVES
 * ================================================================ */
namespace Zef\Framework\Validation {
    use Zef\Framework\Exception\InvalidHeaderException;
    use Zef\Framework\Exception\InvalidConfigurationException;
    use Zef\Framework\Exception\ModuleDependencyViolationException;
    use Zef\Framework\Exception\RouteConstraintException;
    use Zef\Framework\Exception\ServiceCircularDependencyException;
    use Zef\Framework\Exception\CircularAliasException;
    use Zef\Framework\Exception\ServiceNotFoundException;

    final class HeaderValidator
    {
        public function assertName(string $name): void
        {
            if ($name === '' || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
                throw new InvalidHeaderException("Invalid header name '{$name}'.");
            }
        }

        public function assertValue(string $name, string $value): void
        {
            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new InvalidHeaderException("Invalid header value for '{$name}'.");
            }
        }
    }

    final class HttpStatusValidator
    {
        public function assert(int $code): void
        {
            if ($code < 100 || $code > 599) {
                throw new \InvalidArgumentException("Invalid HTTP status code: {$code}.");
            }
        }
    }

    final class TrustedHostValidator
    {
        public function __construct(private readonly array $trustedHosts = []) {}

        public function assert(string $host): void
        {
            if ($host === '' || $this->trustedHosts === []) return;
            $normalize = static function (string $value): string {
                $value = strtolower(trim($value));
                if (strlen($value) >= 2 && $value[0] === '[' && $value[strlen($value) - 1] === ']') {
                    $value = substr($value, 1, -1);
                }
                return $value;
            };
            $normalized = $normalize($host);
            foreach ($this->trustedHosts as $allowed) {
                if ($normalized === $normalize((string) $allowed)) return;
            }
            throw new \InvalidArgumentException("Untrusted host: {$host}.");
        }
    }

    final class PortRangeValidator
    {
        public function assert(?int $port): void
        {
            if ($port !== null && ($port < 1 || $port > 65535)) {
                throw new \InvalidArgumentException("Invalid port: {$port}.");
            }
        }
    }

    final class RouteConstraintValidator
    {
        private const BUILT_IN = [
            'int'   => '/^\d+$/',
            'uint'  => '/^[1-9]\d*$/',
            'alpha' => '/^[a-zA-Z]+$/',
            'slug'  => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'uuid'  => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            'hex'   => '/^[0-9a-f]+$/i',
        ];

        private array $custom = [];

        public function addCustom(string $name, string $regex): void
        {
            if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new InvalidConfigurationException("Invalid route constraint name '{$name}'.");
            }
            $previous = set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
            try {
                try {
                    $result = preg_match($regex, '');
                } catch (\ValueError $e) {
                    throw new InvalidConfigurationException(
                        "Invalid route constraint regex '{$name}': {$e->getMessage()}",
                        0,
                        $e
                    );
                }
            } catch (\ErrorException $e) {
                throw new InvalidConfigurationException(
                    "Invalid route constraint regex '{$name}': {$e->getMessage()}",
                    0,
                    $e
                );
            } finally {
                restore_error_handler();
            }

            if ($result === false) {
                throw new InvalidConfigurationException(
                    "Invalid route constraint regex '{$name}': " . preg_last_error_msg()
                );
            }
            $this->custom[$name] = $regex;
        }

        public function test(string $param, string $type, string $value): bool
        {
            $this->assertKnown($type);
            $regex = $this->custom[$type] ?? self::BUILT_IN[$type];
            $matched = preg_match($regex, $value);
            if ($matched === false) {
                throw new InvalidConfigurationException(
                    "Route constraint '{$type}' failed during evaluation: " . preg_last_error_msg()
                );
            }
            return $matched === 1;
        }

        public function assertKnown(string $type): void { if (!isset($this->custom[$type]) && !isset(self::BUILT_IN[$type])) throw new InvalidConfigurationException("Unknown route constraint type '{$type}'."); }

        public function assert(string $param, string $type, string $value): void
        {
            if (!$this->test($param, $type, $value)) {
                throw new RouteConstraintException($param, $type, $value);
            }
        }
    }

    final class DependencyGraphValidator
    {
        public function validate(array $factories, array $aliases, array $depsOf, array $moduleOf, array $lifetimeOf = [], int $maxCrossModuleRefs = 0): void
        {
            $counts = [];
            $edgeSeen = [];
            foreach ($factories as $id => $_factory) {
                foreach ($depsOf[$id] ?? [] as $dep) {
                    $canonical = $this->resolveAlias($dep, $aliases);
                    if (!isset($factories[$canonical])) {
                        throw new ServiceNotFoundException((string) $dep, $moduleOf[$id] ?? null);
                    }
                    if (($lifetimeOf[$id] ?? 'singleton') === 'singleton') {
                        $this->assertSingletonClosure((string)$id, $canonical, $depsOf, $aliases, $lifetimeOf, []);
                    }

                    if ($maxCrossModuleRefs > 0) {
                        $from = $moduleOf[$id] ?? null;
                        $to   = $moduleOf[$canonical] ?? null;
                        if ($from !== null && $to !== null && $from !== $to) {
                            $key = $from . '->' . $to;
                            $edgeKey = $key . '|' . $canonical;
                            if (isset($edgeSeen[$edgeKey])) continue;
                            $edgeSeen[$edgeKey] = true;
                            $counts[$key] = ($counts[$key] ?? 0) + 1;
                            if ($counts[$key] > $maxCrossModuleRefs) {
                                throw new ModuleDependencyViolationException(
                                    "Module '{$from}' exceeds cross-module reference limit ({$maxCrossModuleRefs}) towards '{$to}'."
                                );
                            }
                        }
                    }
                }
            }

            $state = [];
            $stack = [];
            foreach (array_keys($factories) as $id) {
                if (($state[$id] ?? 0) === 0) {
                    $this->dfs($id, $depsOf, $aliases, $state, $stack);
                }
            }

            foreach (array_keys($aliases) as $alias) {
                $canonical = $this->resolveAlias($alias, $aliases);
                if (!isset($factories[$canonical])) {
                    throw new ServiceNotFoundException((string) $alias, $moduleOf[$alias] ?? null);
                }
            }
        }

        private function assertSingletonClosure(string $owner, string $current, array $depsOf, array $aliases, array $lifetimeOf, array $seen): void
        {
            if (isset($seen[$current])) return;
            $seen[$current] = true;
            $life = (string)($lifetimeOf[$current] ?? 'singleton');
            if ($life !== 'singleton') {
                throw new InvalidConfigurationException("Singleton service '{$owner}' transitively depends on {$life} service '{$current}'.");
            }
            foreach ($depsOf[$current] ?? [] as $dep) {
                $canonical = $this->resolveAlias($dep, $aliases);
                $this->assertSingletonClosure($owner, $canonical, $depsOf, $aliases, $lifetimeOf, $seen);
            }
        }

        private function dfs(string $id, array $depsOf, array $aliases, array &$state, array &$stack): void
        {
            $state[$id] = 1;
            $stack[] = $id;
            foreach ($depsOf[$id] ?? [] as $dep) {
                $canonical = $this->resolveAlias($dep, $aliases);
                if (($state[$canonical] ?? 0) === 1) {
                    $pos = array_search($canonical, $stack, true);
                    $cycle = $pos === false ? [$canonical] : array_slice($stack, $pos);
                    $cycle[] = $canonical;
                    throw new ServiceCircularDependencyException($cycle);
                }
                if (($state[$canonical] ?? 0) === 0) {
                    $this->dfs($canonical, $depsOf, $aliases, $state, $stack);
                }
            }
            array_pop($stack);
            $state[$id] = 2;
        }

        public function resolveAlias(string $id, array $aliases): string
        {
            $seen = [];
            $current = $id;
            while (isset($aliases[$current])) {
                if (isset($seen[$current])) {
                    $chain = array_keys($seen);
                    $chain[] = $current;
                    throw new CircularAliasException($chain);
                }
                $seen[$current] = true;
                $next = $aliases[$current];
                if (!is_string($next) || $next === '') {
                    throw new InvalidConfigurationException("Alias '{$current}' must target a non-empty service ID.");
                }
                $current = $next;
            }
            return $current;
        }
    }
}

/* ================================================================
 * SECTION 7 — CONSTANTS
 * ================================================================ */
namespace Zef\Framework\Constant {
    final class HttpReasonPhrases
    {
        public const MAP = [
            100=>'Continue',101=>'Switching Protocols',102=>'Processing',103=>'Early Hints',
            200=>'OK',201=>'Created',202=>'Accepted',203=>'Non-Authoritative Information',204=>'No Content',205=>'Reset Content',206=>'Partial Content',207=>'Multi-Status',208=>'Already Reported',226=>'IM Used',
            300=>'Multiple Choices',301=>'Moved Permanently',302=>'Found',303=>'See Other',304=>'Not Modified',307=>'Temporary Redirect',308=>'Permanent Redirect',
            400=>'Bad Request',401=>'Unauthorized',402=>'Payment Required',403=>'Forbidden',404=>'Not Found',405=>'Method Not Allowed',406=>'Not Acceptable',407=>'Proxy Authentication Required',408=>'Request Timeout',409=>'Conflict',410=>'Gone',411=>'Length Required',412=>'Precondition Failed',413=>'Content Too Large',414=>'URI Too Long',415=>'Unsupported Media Type',416=>'Range Not Satisfiable',417=>'Expectation Failed',418=>"I'm a teapot",421=>'Misdirected Request',422=>'Unprocessable Content',423=>'Locked',424=>'Failed Dependency',425=>'Too Early',426=>'Upgrade Required',428=>'Precondition Required',429=>'Too Many Requests',431=>'Request Header Fields Too Large',451=>'Unavailable For Legal Reasons',
            500=>'Internal Server Error',501=>'Not Implemented',502=>'Bad Gateway',503=>'Service Unavailable',504=>'Gateway Timeout',505=>'HTTP Version Not Supported',506=>'Variant Also Negotiates',507=>'Insufficient Storage',508=>'Loop Detected',510=>'Not Extended',511=>'Network Authentication Required',
        ];
    }
}

/* ================================================================
 * SECTION 8 — HTTP MESSAGE IMPLEMENTATIONS
 * ================================================================ */
namespace Zef\Framework\Http {
    use Psr\Http\Message\MessageInterface;
    use Psr\Http\Message\RequestInterface;
    use Psr\Http\Message\StreamInterface;
    use Psr\Http\Message\UriInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\UploadedFileInterface;
    use Zef\Framework\Constant\HttpReasonPhrases;
    use Zef\Framework\Validation\HeaderValidator;
    use Zef\Framework\Validation\HttpStatusValidator;
    use Zef\Framework\Validation\PortRangeValidator;
    use Zef\Framework\Validation\TrustedHostValidator;
    use Zef\Framework\Exception\InvalidConfigurationException;

    final class Stream implements StreamInterface
    {
        private $resource;
        private ?int $size = null;

        public function __construct($resource)
        {
            if (!is_resource($resource)) {
                throw new \InvalidArgumentException('Stream resource required.');
            }
            $this->resource = $resource;
        }

        public static function fromString(string $content): self
        {
            $resource = fopen('php://temp', 'w+b');
            if ($resource === false) throw new \RuntimeException('Unable to create memory stream.');
            $stream = new self($resource);
            if ($content !== '') $stream->write($content);
            $stream->rewind();
            return $stream;
        }

        public function __toString(): string
        {
            try {
                if (!is_resource($this->resource) || !$this->isReadable()) return '';
                if ($this->isSeekable()) {
                    $this->rewind();
                }
                $contents = stream_get_contents($this->resource);
                return $contents === false ? '' : $contents;
            } catch (\Throwable) {
                return '';
            }
        }

        public function close(): void
        {
            if (is_resource($this->resource)) @fclose($this->resource);
            $this->resource = null;
            $this->size = null;
        }

        public function detach(): mixed
        {
            $resource = $this->resource;
            $this->resource = null;
            $this->size = null;
            return $resource;
        }

        public function getSize(): ?int
        {
            if (!is_resource($this->resource)) return null;
            $stats = fstat($this->resource);
            return is_array($stats) && isset($stats['size']) ? (int)$stats['size'] : null;
        }

        public function tell(): int
        {
            if (!is_resource($this->resource)) throw new \RuntimeException('Stream detached.');
            $pos = ftell($this->resource);
            if ($pos === false) throw new \RuntimeException('Unable to determine stream position.');
            return $pos;
        }

        public function eof(): bool { return !is_resource($this->resource) || feof($this->resource); }

        public function isSeekable(): bool
        {
            return is_resource($this->resource) && (bool)$this->metadata('seekable');
        }

        public function seek(int $offset, int $whence = SEEK_SET): void
        {
            if (!is_resource($this->resource) || !$this->isSeekable() || fseek($this->resource, $offset, $whence) !== 0) {
                throw new \RuntimeException('Stream is not seekable.');
            }
        }

        public function rewind(): void { $this->seek(0); }

        public function isWritable(): bool
        {
            $mode = (string)$this->metadata('mode');
            return is_resource($this->resource) && $mode !== '' && preg_match('/[waxc+]/', $mode) === 1;
        }

        public function write(string $string): int
        {
            if (!is_resource($this->resource) || !$this->isWritable()) throw new \RuntimeException('Stream is not writable.');
            $written = fwrite($this->resource, $string);
            if ($written === false) throw new \RuntimeException('Unable to write to stream.');
            $this->size = null;
            return $written;
        }

        public function isReadable(): bool
        {
            $mode = (string)$this->metadata('mode');
            return is_resource($this->resource) && $mode !== '' && preg_match('/[r+]/', $mode) === 1;
        }

        public function read(int $length): string
        {
            if ($length < 0) throw new \InvalidArgumentException('Length must be non-negative.');
            if (!is_resource($this->resource) || !$this->isReadable()) throw new \RuntimeException('Stream is not readable.');
            if ($length === 0) return '';
            $data = fread($this->resource, $length);
            if ($data === false) throw new \RuntimeException('Unable to read stream.');
            return $data;
        }

        public function getContents(): string
        {
            if (!is_resource($this->resource) || !$this->isReadable()) throw new \RuntimeException('Stream is not readable.');
            $data = stream_get_contents($this->resource);
            if ($data === false) throw new \RuntimeException('Unable to read stream contents.');
            return $data;
        }

        public function getMetadata(?string $key = null): mixed
        {
            return $this->metadata($key);
        }

        private function metadata(?string $key = null): mixed
        {
            if (!is_resource($this->resource)) return $key === null ? [] : null;
            $meta = stream_get_meta_data($this->resource);
            return $key === null ? $meta : ($meta[$key] ?? null);
        }
    }

    final class Uri implements UriInterface
    {
        private string $scheme = '';
        private string $userInfo = '';
        private string $host = '';
        private ?int $port = null;
        private string $path = '';
        private string $query = '';
        private string $fragment = '';

        public function __construct(string $uri = '', private readonly array $trustedHosts = [])
        {
            if ($uri === '') return;
            $this->assertNoControls($uri, 'URI');
            $parts = parse_url($uri);
            if ($parts === false) throw new \InvalidArgumentException("Unable to parse URI '{$uri}'.");
            $this->scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($this->scheme !== '' && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*\\z/', $this->scheme) !== 1) throw new \InvalidArgumentException('Invalid URI scheme.');
            $user = isset($parts['user']) ? (string)$parts['user'] : '';
            $pass = array_key_exists('pass', $parts) ? (string)$parts['pass'] : null;
            $this->userInfo = $this->encodeComponent($user, "!$&'()*+,;=:");
            if ($pass !== null) $this->userInfo .= ':' . $this->encodeComponent($pass, "!$&'()*+,;=:");
            $this->host = strtolower((string) ($parts['host'] ?? ''));
            $this->assertHost($this->host);
            $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
            (new PortRangeValidator())->assert($this->port);
            $this->path = $this->encodeComponent((string)($parts['path'] ?? ''), ":/@!$&'()*+,;=-._~");
            $this->query = $this->encodeComponent((string)($parts['query'] ?? ''), ":/?@!$&'()*+,;=-._~");
            $this->fragment = $this->encodeComponent((string)($parts['fragment'] ?? ''), ":/?@!$&'()*+,;=-._~");
            (new TrustedHostValidator($this->trustedHosts))->assert($this->host);
        }

        private function assertNoControls(string $value, string $label): void
        {
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) throw new \InvalidArgumentException("Invalid {$label} control characters.");
        }

        private function encodeComponent(string $value, string $allowed): string
        {
            $result=''; $len=strlen($value);
            for($i=0;$i<$len;$i++){
                $ch=$value[$i]; $o=ord($ch);
                if($ch==='%' && $i+2<$len && ctype_xdigit($value[$i+1]) && ctype_xdigit($value[$i+2])){$result.='%'.strtoupper($value[$i+1].$value[$i+2]);$i+=2;continue;}
                if(($o>=65&&$o<=90)||($o>=97&&$o<=122)||($o>=48&&$o<=57)||str_contains("-._~".$allowed,$ch)){$result.=$ch;continue;}
                $result .= sprintf('%%%02X',$o);
            }
            return $result;
        }

        private function assertHost(string $host): void
        {
            if ($host === '') return;
            $this->assertNoControls($host, 'URI host');
            if (str_contains($host, ':')) {
                if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) throw new \InvalidArgumentException('Invalid URI host.');
                return;
            }
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return;
            if (strlen($host)>253 || preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/', $host) !== 1) throw new \InvalidArgumentException('Invalid URI host.');
        }

        public function getScheme(): string { return $this->scheme; }
        public function getAuthority(): string
        {
            if ($this->host === '') return '';
            $displayHost = str_contains($this->host, ':') && !str_starts_with($this->host, '[') ? '[' . $this->host . ']' : $this->host;
            $authority = ($this->userInfo !== '' ? $this->userInfo . '@' : '') . $displayHost;
            if ($this->port !== null) $authority .= ':' . $this->port;
            return $authority;
        }
        public function getUserInfo(): string { return $this->userInfo; }
        public function getHost(): string { return $this->host; }
        public function getPort(): ?int { return $this->port; }
        public function getPath(): string { return $this->path; }
        public function getQuery(): string { return $this->query; }
        public function getFragment(): string { return $this->fragment; }

        public function withScheme(string $scheme): UriInterface {
                        $this->assertNoControls($scheme, 'URI scheme');
            if ($scheme !== '' && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*\\z/', $scheme) !== 1) throw new \InvalidArgumentException('Invalid URI scheme.');
            $n=clone $this; $n->scheme=strtolower($scheme); return $n;
        }
        public function withUserInfo(string $user, ?string $password=null): UriInterface { $this->assertNoControls($user,'URI user info'); if($password!==null)$this->assertNoControls($password,'URI user info'); $n=clone $this; $n->userInfo=$this->encodeComponent($user,"!$&'()*+,;=:").($password!==null?':'.$this->encodeComponent($password,"!$&'()*+,;="):''); return $n; }
        public function withHost(string $host): UriInterface { $host=trim($host); if(str_starts_with($host,'[')&&str_ends_with($host,']'))$host=substr($host,1,-1); $this->assertHost($host); $n=clone $this; $n->host=strtolower($host); (new TrustedHostValidator($this->trustedHosts))->assert($n->host); return $n; }
        public function withPort(?int $port): UriInterface { (new PortRangeValidator())->assert($port); $n=clone $this; $n->port=$port; return $n; }
        public function withPath(string $path): UriInterface { $this->assertNoControls($path,'URI path'); $n=clone $this; $n->path=$this->encodeComponent($path,":/@!$&'()*+,;=-._~"); return $n; }
        public function withQuery(string $query): UriInterface { $this->assertNoControls($query,'URI query'); $n=clone $this; $n->query=$this->encodeComponent($query,":/?@!$&'()*+,;=-._~"); return $n; }
        public function withFragment(string $fragment): UriInterface { $this->assertNoControls($fragment,'URI fragment'); $n=clone $this; $n->fragment=$this->encodeComponent($fragment,":/?@!$&'()*+,;=-._~"); return $n; }
        public function __toString(): string { $uri=$this->scheme!==''?$this->scheme.':':''; if($this->getAuthority()!=='')$uri.='//'.$this->getAuthority(); $uri.=$this->path; if($this->query!=='')$uri.='?'.$this->query; if($this->fragment!=='')$uri.='#'.$this->fragment; return $uri; }
    }

    abstract class MessageBase
    {
        protected string $protocolVersion = '1.1';
        /** @var array<string,array<int,string>> */
        protected array $headers = [];
        /** @var array<string,string> lowercase header name => stored/original header key */
        private array $headerNames = [];
        protected StreamInterface $body;
        protected HeaderValidator $headerValidator;

        public function __construct(?StreamInterface $body = null, array $headers = [], string $protocolVersion = '1.1')
        {
            $this->body = $body ?? Stream::fromString('');
            $this->headerValidator = new HeaderValidator();
            if (preg_match('/^\d+\.\d+$/', $protocolVersion) !== 1) throw new \InvalidArgumentException('Invalid HTTP protocol version.');
            $this->protocolVersion = $protocolVersion;
            foreach ($headers as $name => $value) $this->setHeader((string) $name, $value);
        }

        public function getProtocolVersion(): string { return $this->protocolVersion; }
        public function withProtocolVersion(string $version): MessageInterface { if(preg_match('/^\d+\.\d+$/',$version)!==1) throw new \InvalidArgumentException('Invalid HTTP protocol version.'); $n=clone $this; $n->protocolVersion=$version; return $n; }
        public function getHeaders(): array { return $this->headers; }
        public function hasHeader(string $name): bool { return $this->findHeaderKey($name) !== null; }
        public function getHeader(string $name): array { $key=$this->findHeaderKey($name); return $key===null?[]:$this->headers[$key]; }
        public function getHeaderLine(string $name): string { return implode(', ', $this->getHeader($name)); }
        public function withHeader(string $name, $value): MessageInterface
        {
            $n = clone $this;
            $n->validateHeader($name, $value);
            $key = strtolower($name);
            $old = $n->headerNames[$key] ?? null;
            if ($old !== null) unset($n->headers[$old]);
            $n->headers[$name] = array_map('strval', is_array($value) ? $value : [$value]);
            $n->headerNames[$key] = $name;
            return $n;
        }

        public function withAddedHeader(string $name, $value): MessageInterface
        {
            $n = clone $this;
            $n->validateHeader($name, $value);
            $key = strtolower($name);
            $old = $n->headerNames[$key] ?? null;
            $values = array_map('strval', is_array($value) ? $value : [$value]);
            if ($old !== null) {
                $n->headers[$old] = array_merge($n->headers[$old], $values);
            } else {
                $n->headers[$name] = $values;
                $n->headerNames[$key] = $name;
            }
            return $n;
        }

        public function withoutHeader(string $name): MessageInterface
        {
            $n = clone $this;
            $key = strtolower($name);
            $old = $n->headerNames[$key] ?? null;
            if ($old !== null) {
                unset($n->headers[$old], $n->headerNames[$key]);
            }
            return $n;
        }
        public function getBody(): StreamInterface { return $this->body; }
        public function withBody(StreamInterface $body): MessageInterface { $n=clone $this; $n->body=$body; return $n; }
        public function bodyString(): string { $pos = null; try { if ($this->body->isSeekable()) $pos=$this->body->tell(); $this->body->rewind(); $s=$this->body->getContents(); if ($pos!==null) $this->body->seek($pos); return $s; } catch (\Throwable) { return ''; } }
        protected function addInitialHeader(string $name, $value): void { $this->setHeader($name,$value); }
        private function setHeader(string $name, $value): void
        {
            $this->validateHeader($name, $value);
            $key = strtolower($name);
            $old = $this->headerNames[$key] ?? null;
            if ($old !== null) unset($this->headers[$old]);
            $this->headers[$name] = array_map('strval', is_array($value) ? $value : [$value]);
            $this->headerNames[$key] = $name;
        }

        private function findHeaderKey(string $name): ?string
        {
            return $this->headerNames[strtolower($name)] ?? null;
        }
        private function validateHeader(string $name, $value): void { $this->headerValidator->assertName($name); foreach (is_array($value)?$value:[$value] as $v) $this->headerValidator->assertValue($name,(string)$v); }
    }

    final class Request extends MessageBase implements RequestInterface
    {
        private ?string $requestTargetOverride;
        private string $method;
        private UriInterface $uri;
        public function __construct(string $method, UriInterface $uri, array $headers = [], ?StreamInterface $body = null, string $protocolVersion = '1.1', string $requestTarget = '')
        {
            parent::__construct($body, $headers, $protocolVersion);
            if ($method === '' || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $method) !== 1) throw new \InvalidArgumentException('Invalid HTTP method.');
            if (!$this->hasHeader('Host') && $uri->getHost() !== '') {
                $host=$uri->getHost(); if($uri->getPort()!==null)$host.=':'.$uri->getPort(); $this->addInitialHeader('Host',$host);
            }
            $this->method=$method; $this->uri=$uri; $this->requestTargetOverride=$requestTarget!==''?$requestTarget:null;
        }
        private function deriveRequestTarget(UriInterface $uri):string{$path=$uri->getPath();$target=$path===''?'/':($path[0]==='/'?$path:'/'.$path);if($uri->getQuery()!=='')$target.='?'.$uri->getQuery();return $target;}
        public function getRequestTarget():string{return $this->requestTargetOverride??$this->deriveRequestTarget($this->uri);}
        public function withRequestTarget(string $requestTarget): RequestInterface {if(preg_match('/[\x00-\x1F\x7F]/',$requestTarget)===1)throw new \InvalidArgumentException('Invalid request target.');$n=clone $this;$n->requestTargetOverride=$requestTarget;return $n;}
        public function getMethod():string{return $this->method;}
        public function withMethod(string $method): RequestInterface {if($method===''||preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/',$method)===1){}else throw new \InvalidArgumentException('Invalid HTTP method.');$n=clone $this;$n->method=$method;return $n;}
        public function getUri():UriInterface{return $this->uri;}
        public function withUri(UriInterface $uri,bool $preserveHost=false): RequestInterface {$n=clone $this;$n->uri=$uri;$existingHost=$this->getHeaderLine('Host');if(!$preserveHost||$existingHost===''){if($uri->getHost()!==''){$host=$uri->getHost();if($uri->getPort()!==null)$host.=':'.$uri->getPort();$n=$n->withHeader('Host',$host);}}return $n;}
    }

    final class ServerRequest extends MessageBase implements ServerRequestInterface
    {
        private ?string $requestTargetOverride;
        private string $method;
        private UriInterface $uri;
        private array $serverParams;
        private array $cookieParams;
        private array $queryParams;
        private array $uploadedFiles;
        private mixed $parsedBody;
        private array $attributes;

        public function __construct(
            string $method,
            UriInterface $uri,
            array $serverParams = [],
            array $cookieParams = [],
            array $queryParams = [],
            array $uploadedFiles = [],
            mixed $parsedBody = null,
            array $headers = [],
            ?StreamInterface $body = null,
            string $protocolVersion = '1.1',
            string $requestTarget = '',
            array $attributes = []
        ) {
            if(!is_array($parsedBody)&&!is_object($parsedBody)&&$parsedBody!==null) throw new \InvalidArgumentException('Parsed body must be array, object or null.');
            parent::__construct($body, $headers, $protocolVersion);
            if(!$this->hasHeader('Host') && $uri->getHost()!=='') {
                $host=$uri->getHost(); if($uri->getPort()!==null)$host.=':'.$uri->getPort();
                $this->addInitialHeader('Host',$host);
            }
            $this->method=$method; $this->uri=$uri; $this->serverParams=$serverParams; $this->cookieParams=$cookieParams; $this->queryParams=$queryParams; $this->uploadedFiles=$uploadedFiles; $this->parsedBody=$parsedBody; $this->attributes=$attributes;
            $this->requestTargetOverride = $requestTarget !== '' ? $requestTarget : null;
        }

        private function deriveRequestTarget(UriInterface $uri): string
        {
            $path = $uri->getPath();
            $target = $path === '' ? '/' : ($path[0] === '/' ? $path : '/' . $path);
            if ($uri->getQuery() !== '') $target .= '?' . $uri->getQuery();
            return $target;
        }

        public function getRequestTarget(): string { return $this->requestTargetOverride ?? $this->deriveRequestTarget($this->uri); }
        public function withRequestTarget(string $requestTarget): ServerRequestInterface { if (preg_match('/[\x00-\x1F\x7F]/',$requestTarget)===1) throw new \InvalidArgumentException('Invalid request target.'); $n=clone $this; $n->requestTargetOverride=$requestTarget; return $n; }
        public function getMethod(): string { return $this->method; }
        public function withMethod(string $method): ServerRequestInterface { if ($method === '' || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $method) !== 1) throw new \InvalidArgumentException('Invalid HTTP method.'); $n=clone $this; $n->method=$method; return $n; }
        public function getUri(): UriInterface { return $this->uri; }
        public function withUri(UriInterface $uri, bool $preserveHost = false): ServerRequestInterface
        {
            $n=clone $this; $n->uri=$uri;
            $existingHost = $this->getHeaderLine('Host');
            if (!$preserveHost || $existingHost === '') {
                if ($uri->getHost() !== '') {
                    $host=$uri->getHost();
                    if ($uri->getPort() !== null) $host .= ':' . $uri->getPort();
                    $n=$n->withHeader('Host',$host);
                }
            }
            return $n;
        }
        public function getServerParams(): array { return $this->serverParams; }
        public function getCookieParams(): array { return $this->cookieParams; }
        public function withCookieParams(array $cookies): ServerRequestInterface { $n=clone $this; $n->cookieParams=$cookies; return $n; }
        public function getQueryParams(): array { return $this->queryParams; }
        public function withQueryParams(array $query): ServerRequestInterface { $n=clone $this; $n->queryParams=$query; return $n; }
        public function getUploadedFiles(): array { return $this->uploadedFiles; }
        public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface { $this->assertUploadedTree($uploadedFiles); $n=clone $this; $n->uploadedFiles=$uploadedFiles; return $n; }
        private function assertUploadedTree(array $tree): void { foreach($tree as $value){ if($value instanceof \Psr\Http\Message\UploadedFileInterface) continue; if(is_array($value)){ $this->assertUploadedTree($value); continue; } throw new \InvalidArgumentException('Uploaded files must contain only UploadedFileInterface leaves.'); } }
        public function getParsedBody(): mixed { return $this->parsedBody; }
        public function withParsedBody($data): ServerRequestInterface { if (!is_array($data) && !is_object($data) && $data !== null) throw new \InvalidArgumentException('Parsed body must be array, object or null.'); $n=clone $this; $n->parsedBody=$data; return $n; }
        public function getAttributes(): array { return $this->attributes; }
        public function getAttribute(string $name, mixed $default = null): mixed { return $this->attributes[$name] ?? $default; }
        public function withAttribute(string $name, mixed $value): ServerRequestInterface { $n=clone $this; $n->attributes[$name]=$value; return $n; }
        public function withoutAttribute(string $name): ServerRequestInterface { $n=clone $this; unset($n->attributes[$name]); return $n; }
    }

    final class Response extends MessageBase implements ResponseInterface
    {
        private int $status;
        private string $reasonPhrase;

        public function __construct(int $status = 200, array $headers = [], string|StreamInterface $body = '', string $reasonPhrase = '', string $protocolVersion = '1.1')
        {
            $bodyStream = is_string($body) ? Stream::fromString($body) : $body;
            parent::__construct($bodyStream, $headers, $protocolVersion);
            (new \Zef\Framework\Validation\HttpStatusValidator())->assert($status);
            $this->status=$status;
            $this->reasonPhrase=$reasonPhrase !== '' ? $reasonPhrase : (HttpReasonPhrases::MAP[$status] ?? '');
        }

        public function getStatusCode(): int { return $this->status; }
        public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface { (new \Zef\Framework\Validation\HttpStatusValidator())->assert($code); $n=clone $this; $n->status=$code; $n->reasonPhrase=$reasonPhrase!==''?$reasonPhrase:(HttpReasonPhrases::MAP[$code]??''); return $n; }
        public function getReasonPhrase(): string { return $this->reasonPhrase; }
    }

    final class UploadedFile implements UploadedFileInterface
    {
        private bool $moved=false;
        public function __construct(private StreamInterface $stream,private ?int $size=null,private int $error=UPLOAD_ERR_OK,private ?string $clientFilename=null,private ?string $clientMediaType=null){}
        public function getStream():StreamInterface{if($this->error!==UPLOAD_ERR_OK)throw new \RuntimeException("Uploaded file is not available (error {$this->error}).");if($this->moved)throw new \RuntimeException('Uploaded file has already been moved.');return $this->stream;}
        public function moveTo(string $targetPath):void
        {
            if ($targetPath === '') throw new \InvalidArgumentException('Target path must not be empty.');
            if ($this->error !== UPLOAD_ERR_OK) throw new \RuntimeException("Cannot move uploaded file with error code {$this->error}.");
            if ($this->moved) throw new \RuntimeException('Uploaded file has already been moved.');
            $directory=dirname($targetPath); if(!is_dir($directory)) throw new \RuntimeException("Destination directory does not exist: '{$directory}'.");
            $tmpTarget=$targetPath.'.zef-tmp-'.bin2hex(random_bytes(8));
            $dest = @fopen($tmpTarget, 'x+b');
            if ($dest === false) throw new \RuntimeException("Unable to create temporary upload target.");
            $success = false;
            $originalPosition = null;
            try {
                if ($this->stream->isSeekable()) { $originalPosition=$this->stream->tell(); $this->stream->rewind(); }
                while (!$this->stream->eof()) {
                    $chunk = $this->stream->read(8192);
                    if ($chunk === '') break;
                    $offset = 0; $length = strlen($chunk);
                    while ($offset < $length) {
                        $written = fwrite($dest, substr($chunk, $offset));
                        if ($written === false || $written === 0) throw new \RuntimeException('Unable to write uploaded file.');
                        $offset += $written;
                    }
                }
                if (function_exists('fflush')) @fflush($dest);
                @fclose($dest); $dest=null;
                if (!@rename($tmpTarget,$targetPath)) throw new \RuntimeException("Unable to finalize uploaded file to '{$targetPath}'.");
                $success=true;
            } finally {
                if (is_resource($dest)) @fclose($dest);
                if (!$success) @unlink($tmpTarget);
                if (!$success && $originalPosition!==null) { try { $this->stream->seek($originalPosition); } catch (\Throwable) {} }
            }
            if ($success) { $this->moved = true; $this->stream->close(); }
        }
        public function getSize():?int{return $this->size??$this->stream->getSize();}
        public function getError():int{return $this->error;}
        public function getClientFilename():?string{return $this->clientFilename;}
        public function getClientMediaType():?string{return $this->clientMediaType;}
    }
}

/* ================================================================
 * SECTION 9 — PSR-17 FACTORIES
 * ================================================================ */
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
        public function createRequest(string $method, $uri): RequestInterface { $u=$uri instanceof UriInterface?$uri:new Uri((string)$uri); return new Request($method,$u); }
        public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface { return new Response($code, reasonPhrase:$reasonPhrase); }
        public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface { $u=$uri instanceof UriInterface?$uri:new Uri((string)$uri); return new ServerRequest($method,$u,$serverParams); }
        public function createStream(string $content = ''): StreamInterface { return Stream::fromString($content); }
        public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface { if($filename==='')throw new \InvalidArgumentException('Filename must not be empty.'); if(preg_match('/^[rwaxtc](?:[bt])?(?:\+)?e?\z/',$mode)!==1 && preg_match('/^[rwaxtc](?:\+)?[bt]e?\z/',$mode)!==1)throw new \InvalidArgumentException("Invalid stream mode '{$mode}'."); $warning=null; set_error_handler(static function(int $severity,string $message)use(&$warning):bool{$warning=$message;return true;}); try{$h=fopen($filename,$mode);}finally{restore_error_handler();} if($h===false)throw new \RuntimeException("Unable to open '{$filename}'".($warning!==null?": {$warning}":'.')); return new Stream($h); }
        public function createStreamFromResource($resource): StreamInterface { if(!is_resource($resource))throw new \InvalidArgumentException('Resource required.'); $meta=stream_get_meta_data($resource); $mode=(string)($meta['mode']??''); if($mode===''||preg_match('/[r+]/',$mode)!==1)throw new \InvalidArgumentException('Resource must be readable.'); return new Stream($resource); }
        public function createUploadedFile(StreamInterface $stream, ?int $size = null, int $error = UPLOAD_ERR_OK, ?string $clientFilename = null, ?string $clientMediaType = null): UploadedFileInterface { if(!$stream->isReadable())throw new \InvalidArgumentException('Uploaded file stream must be readable.'); if($size!==null&&$size<0)throw new \InvalidArgumentException('Uploaded file size must not be negative.'); return new UploadedFile($stream,$size??$stream->getSize(),$error,$clientFilename,$clientMediaType); }
        public function createUri(string $uri = ''): UriInterface { return new Uri($uri); }
    }
}

/* ================================================================
 * SECTION 10 — CONFIGURATION
 * ================================================================ */
namespace Zef\Framework\Config {
    use Zef\Framework\Exception\InvalidConfigurationException;

    interface ConfigProviderInterface
    {
        public function getModuleName(): string;
        public function getConfig(): array;
    }

    final class ConfigAggregator
    {
        private array $providers=[];
        private array $merged=[];
        private bool $mergedReady=false;

        public function addProvider(ConfigProviderInterface $provider): void
        {
            if ($this->mergedReady) throw new \LogicException('Cannot add provider after configuration has been merged.');
            $module=$provider->getModuleName();
            if ($module === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/',$module)!==1) throw new InvalidConfigurationException("Invalid module name '{$module}'.");
            $moduleKey = strtolower($module);
            foreach ($this->providers as $existing) {
                if (strtolower($existing->getModuleName()) === $moduleKey) {
                    throw new InvalidConfigurationException("Duplicate module/provider '{$module}' (case-insensitive collision).");
                }
            }
            $this->providers[]=$provider;
        }

        public function merge(): array
        {
            if ($this->mergedReady) return $this->merged;
            $this->merged=[];
            foreach($this->providers as $provider){
                $cfg=$provider->getConfig();
                if(!is_array($cfg)) throw new InvalidConfigurationException("Config provider '{$provider->getModuleName()}' must return an array.");
                $this->merged[strtolower($provider->getModuleName())]=$cfg;
            }
            $this->mergedReady=true;
            return $this->merged;
        }
        public function get(string $key,mixed $default=null):mixed{ $cur=$this->merged; foreach(explode('.',$key) as $s){ if(!is_array($cur)||!array_key_exists($s,$cur)) return $default; $cur=$cur[$s]; } return $cur; }
        public function all():array{return $this->merged;}
        public function providers():array{return $this->providers;}
    }
}

/* ================================================================
 * SECTION 11 — DI CONTAINER WITH LIFETIMES
 * ================================================================ */
namespace Zef\Framework\Container {
    use Psr\Container\ContainerInterface;
    use Zef\Framework\Exception\InvalidFactoryException;
    use Zef\Framework\Exception\ServiceNotFoundException;
    use Zef\Framework\Exception\ServiceResolutionException;
    use Zef\Framework\Exception\ServiceCircularDependencyException;
    use Zef\Framework\Validation\DependencyGraphValidator;

    final class ServiceLifetime
    {
        public const SINGLETON='singleton';
        public const REQUEST='request';
        public const TRANSIENT='transient';
        public static function assert(string $lifetime):void{if(!in_array($lifetime,[self::SINGLETON,self::REQUEST,self::TRANSIENT],true)) throw new \InvalidArgumentException("Unknown service lifetime '{$lifetime}'.");}
    }

    /**
     * Typed, immutable service configuration.
     *
     * This is the canonical representation used by the registry; legacy
     * array configuration is normalized at the module/bootstrap boundary.
     */
    final readonly class ServiceDefinition
    {
        public function __construct(
            public string $id,
            public mixed $factory,
            public array $dependencies = [],
            public ?string $module = null,
            public string $lifetime = ServiceLifetime::SINGLETON,
            public bool $shared = true,
            public bool $lazy = false,
            public array $tags = [],
        ) {
            if ($id === '') {
                throw new \InvalidArgumentException('Service definition ID must not be empty.');
            }
            if (!is_callable($factory)) {
                throw new \InvalidArgumentException("Service '{$id}' factory must be callable.");
            }
            ServiceLifetime::assert($lifetime);
            foreach ($dependencies as $dependency) {
                if (!is_string($dependency) || $dependency === '') {
                    throw new \InvalidArgumentException("Service '{$id}' dependencies must be non-empty strings.");
                }
            }
            foreach ($tags as $tag) {
                if (!is_string($tag) || $tag === '') {
                    throw new \InvalidArgumentException("Service '{$id}' tags must be non-empty strings.");
                }
            }
            if ($lifetime !== ServiceLifetime::SINGLETON && $shared) {
                throw new \InvalidArgumentException("Service '{$id}' cannot be shared unless lifetime is singleton.");
            }
        }

        public static function fromArray(string $id, array $config, ?string $module = null): self
        {
            if (!array_key_exists('factory', $config) || !is_callable($config['factory'])) {
                throw new InvalidFactoryException("Factory for '{$id}' is invalid: callable factory required.");
            }
            $deps = $config['deps'] ?? [];
            if (!is_array($deps)) {
                throw new InvalidFactoryException("Factory for '{$id}' is invalid: dependencies must be an array.");
            }
            $lifetime = $config['lifetime'] ?? ServiceLifetime::SINGLETON;
            if (!is_string($lifetime)) {
                throw new InvalidFactoryException("Factory for '{$id}' is invalid: lifetime must be a string.");
            }
            $tags = $config['tags'] ?? [];
            if (!is_array($tags)) {
                throw new InvalidFactoryException("Factory for '{$id}' is invalid: tags must be an array.");
            }
            return new self(
                id: $id,
                factory: $config['factory'],
                dependencies: array_values($deps),
                module: $module,
                lifetime: $lifetime,
                shared: $config['shared'] ?? ($lifetime === ServiceLifetime::SINGLETON),
                lazy: (bool) ($config['lazy'] ?? false),
                tags: array_values($tags),
            );
        }
    }

    final class ServiceRegistry
    {
        /** @var array<string,ServiceDefinition> */
        private array $definitions=[];
        private array $aliases=[]; private array $instances=[]; private array $moduleOf=[];
        public function hasFactory(string $id):bool{return isset($this->definitions[$id]);}
        public function hasAlias(string $id):bool{return isset($this->aliases[$id]);}
        public function definitions():array{return $this->definitions;}
        public function factories():array{ $out=[]; foreach($this->definitions as $id=>$definition)$out[$id]=$definition->factory; return $out;}
        public function aliases():array{return $this->aliases;}
        public function depsOf():array{ $out=[]; foreach($this->definitions as $id=>$definition)$out[$id]=$definition->dependencies; return $out;}
        public function moduleOf():array{return $this->moduleOf;}
        public function lifetimeOf():array{ $out=[]; foreach($this->definitions as $id=>$definition)$out[$id]=$definition->lifetime; return $out;}
        public function hasInstance(string $id):bool{return array_key_exists($id,$this->instances);}
        public function instance(string $id):mixed{return $this->instances[$id];}
        public function setInstance(string $id,mixed $value):void{$this->instances[$id]=$value;}
        public function clearInstances():void{$this->instances=[];}
        public function addDefinition(ServiceDefinition $definition):void{$this->definitions[$definition->id]=$definition;$this->moduleOf[$definition->id]=$definition->module;}
        public function addFactory(string $id,callable $factory,array $deps,?string $module,string $lifetime):void{$this->addDefinition(new ServiceDefinition($id,$factory,array_values($deps),$module,$lifetime,$lifetime===ServiceLifetime::SINGLETON));}
        public function addAlias(string $alias,string $target,?string $module):void{$this->aliases[$alias]=$target;$this->moduleOf[$alias]=$module;}
    }

    final class ServiceRegistryView
    {
        public function __construct(private readonly ServiceRegistry $registry){}
        public function definitions():array{return $this->registry->definitions();}
        public function factories():array{return $this->registry->factories();}
        public function aliases():array{return $this->registry->aliases();}
        public function depsOf():array{return $this->registry->depsOf();}
        public function moduleOf():array{return $this->registry->moduleOf();}
        public function lifetimeOf():array{return $this->registry->lifetimeOf();}
    }

    final class ServiceRegistrar
    {
        public function __construct(private readonly ServiceRegistry $registry){}
        public function register(string $id,callable $factory,array $deps=[],?string $module=null,string $lifetime=ServiceLifetime::SINGLETON):void
        {
            if($id===''||$this->registry->hasFactory($id)||$this->registry->hasAlias($id)) throw new InvalidFactoryException("Factory for '{$id}' is invalid: service ID already registered.");
            ServiceLifetime::assert($lifetime);
            foreach($deps as $dep) if(!is_string($dep)||$dep==='') throw new InvalidFactoryException("Factory for '{$id}' is invalid: dependency IDs must be non-empty strings.");
            $this->registry->addFactory($id,$factory,$deps,$module,$lifetime);
        }
        public function alias(string $alias,string $target,?string $module=null):void
        {
            if($alias===''||$this->registry->hasFactory($alias)||$this->registry->hasAlias($alias)) throw new InvalidFactoryException("Factory for '{$alias}' is invalid: ID already registered.");
            if($target==='') throw new InvalidFactoryException("Alias '{$alias}' must target a non-empty service ID.");
            $this->registry->addAlias($alias,$target,$module);
        }
    }

    final class ResolutionContext implements ContainerInterface
    {
        private array $loading=[];
        public function __construct(private readonly ContainerResolver $resolver, private readonly ?RequestScope $scope){}
        public function get(string $id):mixed{return $this->resolver->resolveInContext($id,$this,$this->scope);}
        public function has(string $id):bool{return $this->resolver->hasInContext($id,$this->scope);}
        public function push(string $id):void{if(isset($this->loading[$id])){$chain=array_keys($this->loading);$chain[]=$id;throw new ServiceCircularDependencyException($chain);}$this->loading[$id]=true;}
        public function pop(string $id):void{unset($this->loading[$id]);}
        public function reset():void{$this->loading=[];}
    }

    final class RequestScope implements ContainerInterface
    {
        private array $instances=[];
        private bool $closed=false;
        private readonly ResolutionContext $context;
        public function __construct(private readonly ContainerResolver $resolver){$this->context=new ResolutionContext($resolver,$this);}
        public function get(string $id):mixed{if($this->closed)throw new \LogicException('Request scope is closed.');return $this->context->get($id);}
        public function has(string $id):bool{return !$this->closed&&$this->resolver->hasInContext($id,$this);}
        public function getInstance(string $id):mixed{return $this->instances[$id];}
        public function hasInstance(string $id):bool{return array_key_exists($id,$this->instances);}
        public function setInstance(string $id,mixed $value):void{$this->instances[$id]=$value;}
        public function close():void{$this->instances=[];$this->context->reset();$this->closed=true;}
        public function isClosed():bool{return $this->closed;}
    }

    final class ContainerResolver
    {
        private ?ContainerInterface $rootContainer=null;
        public function __construct(private readonly ServiceRegistry $registry,private readonly DependencyGraphValidator $graphValidator){}
        public function bind(ContainerInterface $container):void{$this->rootContainer=$container;}
        public function createRequestScope():RequestScope{return new RequestScope($this);}
        public function clearSingletons():void{$this->registry->clearInstances();}
        public function resolveRoot(string $id):mixed{if($this->rootContainer===null)throw new \LogicException('Container resolver is not bound.');$ctx=new ResolutionContext($this,null);try{return $ctx->get($id);}catch(\LogicException $e){throw new ServiceResolutionException($id,$e->getMessage(),$e);}}
        public function resolveInContext(string $id,ResolutionContext $ctx,?RequestScope $scope):mixed
        {
            $canonical=$this->graphValidator->resolveAlias($id,$this->registry->aliases());
            $lifetime=$this->registry->lifetimeOf()[$canonical]??null;
            if($lifetime===null||!$this->registry->hasFactory($canonical))throw new ServiceNotFoundException($id,$this->registry->moduleOf()[$id]??null);
            if($lifetime===ServiceLifetime::SINGLETON&&$this->registry->hasInstance($canonical))return $this->registry->instance($canonical);
            if($lifetime===ServiceLifetime::REQUEST){if($scope===null||$scope->isClosed())throw new \LogicException("Request-scoped service '{$canonical}' resolved outside a request scope.");if($scope->hasInstance($canonical))return $scope->getInstance($canonical);}
            $ctx->push($canonical);
            try{
                $deps=[];foreach($this->registry->depsOf()[$canonical]??[] as $dep)$deps[]=$ctx->get($dep);
                try{$instance=($this->registry->factories()[$canonical])($ctx,...$deps);}catch(\Throwable $e){throw new ServiceResolutionException($canonical,$e->getMessage(),$e);}
                if($instance===null)throw new ServiceResolutionException($canonical,'factory returned null.');
                if($lifetime===ServiceLifetime::SINGLETON)$this->registry->setInstance($canonical,$instance);
                elseif($lifetime===ServiceLifetime::REQUEST&&$scope!==null)$scope->setInstance($canonical,$instance);
                return $instance;
            }finally{$ctx->pop($canonical);}
        }
        public function hasInContext(string $id,?RequestScope $scope):bool{try{$canonical=$this->graphValidator->resolveAlias($id,$this->registry->aliases());return isset($this->registry->lifetimeOf()[$canonical])&&$this->registry->hasFactory($canonical);}catch(\Throwable){return false;}}
    }

    final class Container implements ContainerInterface
    {
        private readonly ServiceRegistry $registry;
        private readonly ServiceRegistrar $registrar;
        private readonly DependencyGraphValidator $graphValidator;
        private readonly ContainerResolver $resolver;
        private bool $frozen=false;
        private int $maxCrossModuleRefs=0;
        public function __construct(private readonly bool $debug=false){$this->registry=new ServiceRegistry();$this->registrar=new ServiceRegistrar($this->registry);$this->graphValidator=new DependencyGraphValidator();$this->resolver=new ContainerResolver($this->registry,$this->graphValidator);$this->resolver->bind($this);}
        public function configurePolicies(int $maxCrossModuleRefs=0):void{if($this->frozen)throw new \LogicException('Container is frozen.');$this->maxCrossModuleRefs=max(0,$maxCrossModuleRefs);}
        public function register(string $id,callable $factory,array $deps=[],?string $module=null,string $lifetime=ServiceLifetime::SINGLETON):void{if($this->frozen)throw new \LogicException('Container is frozen.');$this->registrar->register($id,$factory,$deps,$module,$lifetime);}
        public function registerDefinition(ServiceDefinition $definition):void{if($this->frozen)throw new \LogicException('Container is frozen.');if($this->registry->hasFactory($definition->id)||$this->registry->hasAlias($definition->id))throw new InvalidFactoryException("Factory for '{$definition->id}' is invalid: service ID already registered.");$this->registry->addDefinition($definition);}
        public function alias(string $alias,string $target,?string $module=null):void{if($this->frozen)throw new \LogicException('Container is frozen.');$this->registrar->alias($alias,$target,$module);}
        public function validateAndFreeze():void{$this->graphValidator->validate($this->registry->factories(),$this->registry->aliases(),$this->registry->depsOf(),$this->registry->moduleOf(),$this->registry->lifetimeOf(),$this->maxCrossModuleRefs);$this->frozen=true;}
        public function warmSingletons():void{if(!$this->frozen)throw new \LogicException('Container must be frozen before warming singletons.');foreach($this->registry->lifetimeOf() as $id=>$life){if($life===ServiceLifetime::SINGLETON)$this->get($id);}}
        public function get(string $id):mixed{return $this->resolver->resolveRoot($id);}
        public function has(string $id):bool{return $this->resolver->hasInContext($id,null);}
        public function isDebug():bool{return $this->debug;}
        public function createRequestScope():RequestScope{return $this->resolver->createRequestScope();}
        public function reset(bool $clearSingletons=false):void{$scope=$this->resolver->createRequestScope();$scope->close();if($clearSingletons)$this->resolver->clearSingletons();}
        public function getRegisteredIds():array{return array_values(array_unique(array_merge(array_keys($this->registry->factories()),array_keys($this->registry->aliases()))));}
        public function getAliasMap():array{return $this->registry->aliases();}
        public function getRegistry():ServiceRegistryView{return new ServiceRegistryView($this->registry);}
        public function isFrozen():bool{return $this->frozen;}
    }
}

/* ================================================================
 * SECTION 12 — ROUTER
 * ================================================================ */
namespace Zef\Framework\Router {
    final readonly class RouteDefinition
    {
        public readonly string $method;
        public readonly string $path;
        public readonly string $handler;
        public readonly int $priority;

        public function __construct(
            string $method,
            string $path,
            string $handler,
            int $priority = 0,
        ) {
            $method = strtoupper(trim($method));
            if ($method === '' || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $method) !== 1) {
                throw new \InvalidArgumentException("Invalid HTTP method '{$method}'.");
            }
            if ($path === '' || $path[0] !== '/') {
                throw new \InvalidArgumentException("Route path '{$path}' must begin with '/'.");
            }
            if ($handler === '') {
                throw new \InvalidArgumentException('Route handler service ID must not be empty.');
            }
            $this->method = $method;
            $this->path = $path;
            $this->handler = $handler;
            $this->priority = $priority;
        }

        public static function fromArray(array $config): self
        {
            $method = $config['method'] ?? 'GET';
            $path = $config['path'] ?? '/';
            $handler = $config['handler'] ?? '';
            $priority = $config['priority'] ?? 0;
            if (!is_string($method) || !is_string($path) || !is_string($handler)) {
                throw new \InvalidArgumentException('Route definition method, path, and handler must be strings.');
            }
            if ((!is_int($priority) && !is_float($priority) && !is_string($priority)) || (is_string($priority) && !is_numeric($priority))) {
                throw new \InvalidArgumentException('Route definition priority must be numeric.');
            }
            return new self(
                method: strtoupper(trim($method)),
                path: $path,
                handler: $handler,
                priority: (int) $priority,
            );
        }
    }

    use Zef\Framework\Exception\RouteNotFoundException;
    use Zef\Framework\Exception\RouteConstraintException;
    use Zef\Framework\Validation\RouteConstraintValidator;

    final class Router
    {
        private array $routes = [];
        /** @var array<string,bool> */
        private array $signatureIndex = [];
        private int $sequence = 0;
        private bool $frozen = false;
        private bool $sorted = true;
        private int $maxRoutesBudget = 10000;
        /** @var array<string,array{static:array<string,int[]>,dynamic:int[],root:int[]}> */
        private array $lookup = [];
        /** @var array{static:array<string,int[]>,dynamic:int[],root:int[]} */
        private array $shapeLookup = ['static'=>[],'dynamic'=>[],'root'=>[]];

        public function __construct(private readonly RouteConstraintValidator $constraints = new RouteConstraintValidator()) {}

        public function setMaxRoutesBudget(int $max): void
        {
            if ($this->frozen) throw new \LogicException('Router is frozen.');
            if ($max < 1) throw new \InvalidArgumentException('Route budget must be >= 1.');
            if (count($this->routes) > $max) throw new \InvalidArgumentException('Route budget cannot be lower than current route count.');
            $this->maxRoutesBudget = $max;
        }

        public function getMaxRoutesBudget(): int { return $this->maxRoutesBudget; }

        public function add(string $method, string $pattern, string $handlerService, ?string $module = null, int $priority = 0): void
        {
            if ($this->frozen) throw new \LogicException('Router is frozen.');
            if (count($this->routes) >= $this->maxRoutesBudget) {
                throw new \Zef\Framework\Exception\InvalidConfigurationException(
                    "Router safety budget exceeded: maximum {$this->maxRoutesBudget} route registrations allowed."
                );
            }

            $method = strtoupper(trim($method));
            if ($pattern === '' || $pattern[0] !== '/') throw new \InvalidArgumentException("Route path '{$pattern}' must begin with '/'.");
            if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $method) !== 1) throw new \InvalidArgumentException("Invalid HTTP method '{$method}'.");

            $segments = $this->parsePattern($pattern);
            $names = [];
            foreach ($segments as $segment) {
                if ($segment['dynamic']) {
                    if (isset($names[$segment['name']])) {
                        throw new \InvalidArgumentException("Duplicate route parameter '{$segment['name']}'.");
                    }
                    $names[$segment['name']] = true;
                    if ($segment['constraint'] !== null) $this->constraints->assertKnown($segment['constraint']);
                }
            }

            $signature = $this->canonicalSignature($method, $segments);
            if (isset($this->signatureIndex[$signature])) {
                foreach ($this->routes as $existing) {
                    if ($existing['signature'] === $signature) {
                        throw new \InvalidArgumentException("Duplicate/unreachable route [{$method}] {$pattern}; it collides with {$existing['pattern']}.");
                    }
                }
                // Defensive invariant check: index entry must always map to a route.
                throw new \LogicException("Router signature index corruption detected for '{$signature}'.");
            }

            $staticCount = 0; $constrainedCount = 0;
            foreach ($segments as $segment) {
                if (!$segment['dynamic']) $staticCount++;
                elseif ($segment['constraint'] !== null) $constrainedCount++;
            }

            $this->routes[] = [
                'method'=>$method,
                'pattern'=>$pattern,
                'handler'=>$handlerService,
                'module'=>$module,
                'priority'=>$priority,
                'sequence'=>$this->sequence++,
                'segments'=>$segments,
                'signature'=>$signature,
                'staticCount'=>$staticCount,
                'constrainedCount'=>$constrainedCount,
            ];
            $this->signatureIndex[$signature] = true;
            $this->sorted = false;
        }

        public function addConstraint(string $name, string $regex): void
        {
            if ($this->frozen) throw new \LogicException('Router is frozen.');
            $this->constraints->addCustom($name, $regex);
        }

        public function freeze(): void
        {
            if ($this->frozen) return;
            $this->sortRoutes();
            $this->frozen = true;
        }

        public function getRoutes(): array
        {
            $this->sortRoutes();
            return $this->routes;
        }

        public function match(string $method, string $path): array
        {
            $this->sortRoutes();
            $method = strtoupper(trim($method)); $constraintFailure = null; $allowed = [];
            $effectiveMethods = $method === 'HEAD' ? ['HEAD','GET'] : [$method];
            foreach ($this->shapeCandidateIndexes($path) as $index) {
                $route = $this->routes[$index];
                if ($this->matchesShape($route['segments'],$path)) {
                    $allowed[$route['method']] = true;
                    if ($route['method'] === 'GET') $allowed['HEAD'] = true;
                }
            }
            foreach ($effectiveMethods as $effectiveMethod) {
                foreach ($this->candidateIndexes($effectiveMethod, $path) as $index) {
                    $route = $this->routes[$index];
                $params = $this->matchRoute($route['segments'],$path);
                if ($params === false) continue;
                if ($params instanceof RouteConstraintException) { $constraintFailure ??= $params; continue; }
                return ['handler'=>$route['handler'],'module'=>$route['module'],'params'=>$params,'pattern'=>$route['pattern']];
                }
            }
            if ($constraintFailure instanceof RouteConstraintException) throw $constraintFailure;
            if ($allowed !== []) throw new \Zef\Framework\Exception\MethodNotAllowedException($method,$path,array_keys($allowed));
            throw new RouteNotFoundException($method,$path);
        }

        private function sortRoutes(): void
        {
            if ($this->sorted) return;
            usort($this->routes, static function(array $a,array $b): int {
                foreach (['priority','staticCount','constrainedCount'] as $field) {
                    $cmp=$b[$field]<=>$a[$field];
                    if($cmp!==0)return $cmp;
                }
                return $a['sequence']<=>$b['sequence'];
            });
            $this->rebuildLookup();
            $this->sorted = true;
        }

        private function rebuildLookup(): void
        {
            $this->lookup = [];
            $this->shapeLookup = ['static' => [], 'dynamic' => [], 'root' => []];
            foreach ($this->routes as $i => $route) {
                $method = $route['method'];
                if (!isset($this->lookup[$method])) {
                    $this->lookup[$method] = ['static' => [], 'dynamic' => [], 'root' => []];
                }
                $segments = $route['segments'];
                if ($segments === []) {
                    $this->lookup[$method]['root'][] = $i;
                    $this->shapeLookup['root'][] = $i;
                } elseif ($segments[0]['dynamic']) {
                    $this->lookup[$method]['dynamic'][] = $i;
                    $this->shapeLookup['dynamic'][] = $i;
                } else {
                    $first = (string)$segments[0]['value'];
                    $this->lookup[$method]['static'][$first][] = $i;
                    $this->shapeLookup['static'][$first][] = $i;
                }
            }
        }

        private function candidateIndexes(string $method, string $path): array
        {
            $this->sortRoutes();
            $parts = explode('/', trim($path, '/'));
            if ($path === '/') $parts = [];
            if (!isset($this->lookup[$method])) return [];
            $bucket = $this->lookup[$method];
            if ($parts === []) return $bucket['root'];
            $static = $bucket['static'][$parts[0]] ?? [];
            $dynamic = $bucket['dynamic'];
            if ($static === []) return $dynamic;
            if ($dynamic === []) return $static;
            $combined = array_merge($static, $dynamic);
            usort($combined, fn(int $a,int $b) => $a <=> $b);
            return $combined;
        }

        private function shapeCandidateIndexes(string $path): array
        {
            $this->sortRoutes();
            $parts = explode('/', trim($path, '/'));
            if ($path === '/') $parts = [];
            if ($parts === []) return $this->shapeLookup['root'];
            $static = $this->shapeLookup['static'][$parts[0]] ?? [];
            $dynamic = $this->shapeLookup['dynamic'];
            if ($static === []) return $dynamic;
            if ($dynamic === []) return $static;
            $combined = array_merge($static, $dynamic);
            usort($combined, fn(int $a,int $b) => $a <=> $b);
            return $combined;
        }

        private function parsePattern(string $pattern): array
        {
            $parts = explode('/', trim($pattern,'/')); if($pattern==='/')$parts=[];
            return array_map(function(string $segment){
                if(preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(?::([A-Za-z_][A-Za-z0-9_]*))?\}$/',$segment,$m)===1)return ['dynamic'=>true,'name'=>$m[1],'constraint'=>$m[2]??null];
                if (str_contains($segment, '{') || str_contains($segment, '}')) throw new \InvalidArgumentException("Invalid route segment '{$segment}'.");
                return ['dynamic'=>false,'value'=>$segment];
            },$parts);
        }

        private function canonicalSignature(string $method,array $segments):string
        {
            $parts=[]; foreach($segments as $s)$parts[]=$s['dynamic']?'*'.($s['constraint']??''):$s['value'];
            return $method.'|/'.implode('/',$parts);
        }
        private function matchesShape(array $segments,string $path):bool
        {
            $pathParts=explode('/',trim($path,'/'));if($path==='/')$pathParts=[];if(count($segments)!==count($pathParts))return false;
            foreach($segments as $i=>$segment)if(!$segment['dynamic']&&$segment['value']!==$pathParts[$i])return false;
            return true;
        }
        private function matchRoute(array $segments,string $path):array|false|RouteConstraintException
        {
            $pathParts=explode('/',trim($path,'/'));if($path==='/')$pathParts=[];if(count($segments)!==count($pathParts))return false;$params=[];
            foreach($segments as $i=>$segment){$value=$pathParts[$i]??'';if($segment['dynamic']){if($segment['constraint']!==null&&!$this->constraints->test($segment['name'],$segment['constraint'],$value))return new RouteConstraintException($segment['name'],$segment['constraint'],$value);$params[$segment['name']]=$value;continue;}if($segment['value']!==$value)return false;}return $params;
        }
    }
}

/* ================================================================
 * SECTION 13 — CONFIG-DRIVEN MODULE BOOTSTRAP
 * ================================================================ */
namespace Zef\Framework {
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Psr\Http\Server\MiddlewareInterface;
    use Zef\Framework\Config\ConfigAggregator;
    use Zef\Framework\Config\ConfigProviderInterface;
    use Zef\Framework\Container\Container;
    use Zef\Framework\Container\ServiceLifetime;
    use Zef\Framework\Exception\InvalidConfigurationException;
    use Zef\Framework\Exception\RouteConstraintException;
    use Zef\Framework\Exception\RouteNotFoundException;
    use Zef\Framework\Http\RequestFactory;
    use Zef\Framework\Http\Response;
    use Zef\Framework\Router\Router;
    use Zef\Framework\Router\RouteDefinition;

    /**
     * Canonical typed representation for middleware pipeline entries.
     * Legacy string/array configuration is normalized at the pipeline boundary.
     */
    final readonly class MiddlewareDefinition
    {
        public function __construct(
            public string $serviceId,
            public int $priority = 0,
            public ?string $group = null,
            public array $tags = [],
        ) {
            if ($serviceId === '') {
                throw new \InvalidArgumentException('Middleware service ID must not be empty.');
            }
            if ($group !== null && $group === '') {
                throw new \InvalidArgumentException('Middleware group must not be empty when provided.');
            }
            foreach ($tags as $tag) {
                if (!is_string($tag) || $tag === '') {
                    throw new \InvalidArgumentException('Middleware tags must be non-empty strings.');
                }
            }
        }

        public static function fromArray(array $config): self
        {
            $service = $config['service'] ?? $config['id'] ?? null;
            if (!is_string($service) || $service === '') {
                throw new InvalidConfigurationException('Middleware definition requires a non-empty service ID.');
            }
            $priority = $config['priority'] ?? 0;
            if (!is_int($priority) && !is_float($priority) && !is_string($priority)) {
                throw new InvalidConfigurationException("Middleware '{$service}' priority must be numeric.");
            }
            $tags = $config['tags'] ?? [];
            if (!is_array($tags)) {
                throw new InvalidConfigurationException("Middleware '{$service}' tags must be an array.");
            }
            $group = $config['group'] ?? null;
            if ($group !== null && !is_string($group)) {
                throw new InvalidConfigurationException("Middleware '{$service}' group must be a string or null.");
            }
            try {
                return new self(
                    serviceId: $service,
                    priority: (int) $priority,
                    group: $group,
                    tags: array_values($tags),
                );
            } catch (\InvalidArgumentException $e) {
                throw new InvalidConfigurationException($e->getMessage(), 0, $e);
            }
        }

        public static function fromLegacy(string|array $config): self
        {
            if (is_string($config)) {
                return new self($config);
            }
            return self::fromArray($config);
        }
    }

    final class ModuleBootstrapper
    {
        public function __construct(private readonly Container $container,private readonly Router $router){}
        public function registerModule(string $module,array $config):void
        {
            $services=$config['services']??[]; if(!is_array($services))throw new InvalidConfigurationException("Module '{$module}' services must be an array.");
            foreach($services as $id=>$def){
                if(!is_string($id)||$id==='')throw new InvalidConfigurationException("Module '{$module}' has an invalid service ID.");
                if($def instanceof \Zef\Framework\Container\ServiceDefinition){
                    if($def->id !== $id)throw new InvalidConfigurationException("Service definition ID '{$def->id}' does not match registry key '{$id}'.");
                    $definition=$def;
                    if($definition->module !== $module)$definition=new \Zef\Framework\Container\ServiceDefinition($definition->id,$definition->factory,$definition->dependencies,$module,$definition->lifetime,$definition->shared,$definition->lazy,$definition->tags);
                } else {
                    if(!is_array($def))throw new InvalidConfigurationException("Service '{$id}' has an invalid definition.");
                    try{$definition=\Zef\Framework\Container\ServiceDefinition::fromArray($id,$def,$module);}catch(\InvalidArgumentException $e){throw new InvalidConfigurationException($e->getMessage(),0,$e);}
                }
                $this->container->registerDefinition($definition);
            }
            $aliases=$config['aliases']??[]; if(!is_array($aliases))throw new InvalidConfigurationException("Module '{$module}' aliases must be an array.");
            foreach($aliases as $alias=>$target){if(!is_string($alias)||!is_string($target))throw new InvalidConfigurationException("Module '{$module}' contains a non-string alias definition.");$this->container->alias($alias,$target,$module);}
            $routes=$config['routes']??[]; if(!is_array($routes))throw new InvalidConfigurationException("Module '{$module}' routes must be an array.");
            foreach($routes as $route){
                try {
                    $definition = $route instanceof RouteDefinition ? $route : RouteDefinition::fromArray($route);
                } catch (\Throwable $e) {
                    throw new InvalidConfigurationException("Module '{$module}' contains an invalid route definition: {$e->getMessage()}", 0, $e);
                }
                $this->router->add($definition->method,$definition->path,$definition->handler,$module,$definition->priority);
            }
        }
    }

    final class PipelineFactory
    {
        public function __construct(private readonly Container $container,private readonly ConfigAggregator $config,private readonly RequestHandlerInterface $terminal){}
        public function build(): MiddlewarePipeline
        {
            $entries=$this->config->get('middleware.stack',[]); if(!is_array($entries))$entries=[]; $pipeline=new MiddlewarePipeline([], $this->terminal);
            $definitions=[];
            foreach($entries as $entry){
                try{$definitions[]=\Zef\Framework\MiddlewareDefinition::fromLegacy($entry);}catch(\Throwable $e){throw new InvalidConfigurationException($e->getMessage(),0,$e);}
            }
            foreach($definitions as $definition){$id=$definition->serviceId;if(!$this->container->has($id))throw new InvalidConfigurationException("Middleware service '{$id}' is not registered.");$mw=$this->container->get($id);if(!$mw instanceof MiddlewareInterface)throw new InvalidConfigurationException("Service '{$id}' does not implement MiddlewareInterface.");$pipeline=$pipeline->withMiddleware($mw);} return $pipeline;
        }
    }

    final class Dispatcher implements RequestHandlerInterface
    {
        public function __construct(private readonly Router $router,private readonly Container $container){}
        public function handle(ServerRequestInterface $request):ResponseInterface
        {
            try{
                $match=$this->router->match($request->getMethod(),$request->getUri()->getPath());
                foreach($match['params'] as $key=>$value)$request=$request->withAttribute($key,$value);
                $scope = $request->getAttribute('__zef_request_scope');
                $resolver = $scope instanceof \Zef\Framework\Container\RequestScope ? $scope : $this->container;
                $handler=$resolver->get($match['handler']);
                if(!$handler instanceof RequestHandlerInterface) throw new InvalidConfigurationException("Handler '{$match['handler']}' does not implement RequestHandlerInterface.");
                return $handler->handle($request);
            }catch(RouteNotFoundException){return new Response(404,['Content-Type'=>'application/json'],json_encode(['error'=>'Not Found','method'=>$request->getMethod(),'path'=>$request->getUri()->getPath()],JSON_THROW_ON_ERROR));}
            catch(MethodNotAllowedException $e){return (new Response(405,['Allow'=>implode(', ',$e->allowedMethods),'Content-Type'=>'application/json'],json_encode(['error'=>'Method Not Allowed','method'=>$e->method,'path'=>$e->path,'allow'=>$e->allowedMethods],JSON_THROW_ON_ERROR)));}
            catch(RouteConstraintException $e){return new Response(400,['Content-Type'=>'application/json'],json_encode(['error'=>'Bad Request','detail'=>$e->getMessage()],JSON_THROW_ON_ERROR));}
        }
    }

    final class MiddlewarePipeline implements RequestHandlerInterface
    {
        public function __construct(private readonly array $stack=[],private readonly ?RequestHandlerInterface $terminal=null,private readonly int $index=0){}
        public function withMiddleware(MiddlewareInterface $middleware):self{$s=$this->stack;$s[]=$middleware;return new self($s,$this->terminal,$this->index);}
        public function handle(ServerRequestInterface $request):ResponseInterface
        {
            if($this->index>=count($this->stack)){if($this->terminal===null)return new Response(500,['Content-Type'=>'text/plain'],'Pipeline terminal missing.');return $this->terminal->handle($request);} $next=new self($this->stack,$this->terminal,$this->index+1);return $this->stack[$this->index]->process($request,$next);
        }
    }

    final class ResponseEmitter
    {
        public function emit(ResponseInterface $response, bool $closeBody = false, bool $suppressBody = false):void
        {
            if (!headers_sent()) {
                http_response_code($response->getStatusCode());
                foreach($response->getHeaders() as $name=>$values)foreach($values as $value)header($name.': '.$value,false);
            }
            $body=$response->getBody();
            try {
                $status=$response->getStatusCode();
                if(!$suppressBody && $status>=200 && $status!==204 && $status!==205 && $status!==304){
                    if(!$body->isReadable()) throw new \RuntimeException('Response body stream is not readable.');
                    while(!$body->eof()){ $chunk=$body->read(8192); if($chunk==='')break; echo $chunk; }
                }
            } finally { if($closeBody)$body->close(); }
        }
    }

    final class Application
    {
        private readonly ConfigAggregator $config;
        private readonly Container $container;
        private readonly Router $router;
        private readonly ModuleBootstrapper $bootstrapper;
        private readonly Dispatcher $dispatcher;
        private readonly ResponseEmitter $emitter;
        private ?MiddlewarePipeline $pipeline=null;
        private bool $booted=false;
        private array $providers=[];
        private array $trustedHosts=[]; private array $trustedProxies=[];

        public function __construct(private readonly bool $debug=false, ?\Psr\Log\LoggerInterface $logger=null){$this->config=new ConfigAggregator();$this->container=new Container($debug);$this->router=new Router();$this->bootstrapper=new ModuleBootstrapper($this->container,$this->router);$this->dispatcher=new Dispatcher($this->router,$this->container);$this->emitter=new ResponseEmitter();$this->container->register(\Psr\Log\LoggerInterface::class, static fn() => $logger ?? new \Psr\Log\NullLogger(), [], 'framework', ServiceLifetime::SINGLETON);}
        public function addProvider(ConfigProviderInterface $provider):void{if($this->booted)throw new \LogicException('Cannot add provider after boot.');$this->providers[]=$provider;}
        public function setTrustedHosts(array $hosts):void{if($this->booted)throw new \LogicException('Cannot change trusted hosts after boot.');$this->trustedHosts=array_values(array_filter(array_map('strval',$hosts),static fn($v)=>$v!==''));}
        public function setTrustedProxies(array $proxies):void{if($this->booted)throw new \LogicException('Cannot change trusted proxies after boot.');$this->trustedProxies=array_values(array_filter(array_map('strval',$proxies),static fn($v)=>$v!==''));}
        public function setMaxCrossModuleRefs(int $limit):void{$this->container->configurePolicies($limit);}
        public function boot():void
        {
            if($this->booted)return;
            foreach($this->providers as $p)$this->config->addProvider($p); $merged=$this->config->merge();
            $maxRefs=(int)$this->config->get('framework.container.max_cross_module_refs',0);$this->container->configurePolicies($maxRefs);
            foreach($this->providers as $p){$module=$p->getModuleName();$this->bootstrapper->registerModule($module,$merged[strtolower($module)]??[]);} $this->container->validateAndFreeze(); $this->container->warmSingletons();
            $this->pipeline=(new PipelineFactory($this->container,$this->config,$this->dispatcher))->build();$this->booted=true;
        }
        public function handle(ServerRequestInterface $request):ResponseInterface
        {
            if(!$this->booted)$this->boot();
            $scope=$this->container->createRequestScope();
            $request=$request->withAttribute('__zef_request_scope',$scope);
            try {
                $response = $this->pipeline?->handle($request) ?? new Response(500,['Content-Type'=>'text/plain'],'Application pipeline unavailable.');
                if (strtoupper($request->getMethod()) === 'HEAD') $response = $response->withBody(\Zef\Framework\Http\Stream::fromString(''));
                return $response;
            } finally { $scope->close(); }
        }
        public function handleGlobals():ResponseInterface{try{return $this->handle(RequestFactory::fromGlobals($this->trustedHosts,$this->trustedProxies));}catch(\InvalidArgumentException $e){return new Response(400,['Content-Type'=>'application/json'],json_encode(['error'=>'Bad Request','message'=>$this->debug?$e->getMessage():'Invalid request.'],JSON_THROW_ON_ERROR));}}
        public function emit(ResponseInterface $response):void{$this->emitter->emit($response);}
        public function getContainer():Container{return $this->container;} public function getRouter():Router{return $this->router;} public function getConfigAggregator():ConfigAggregator{return $this->config;} public function isBooted():bool{return $this->booted;} public function getTrustedHosts():array{return $this->trustedHosts;}
    }
}

/* ================================================================
 * SECTION 14 — REQUEST FACTORY FROM PHP GLOBALS
 * ================================================================ */
namespace Zef\Framework\Http {
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Message\UploadedFileInterface;

    final class RequestFactory
    {
        public static function fromGlobals(array $trustedHosts=[],array $trustedProxies=[]):ServerRequestInterface
        {
            $server=$_SERVER; $method=strtoupper((string)($server['REQUEST_METHOD']??'GET')); $protocol=self::protocolVersion((string)($server['SERVER_PROTOCOL']??'HTTP/1.1'));
            $headers=self::extractHeaders($server); $uri=self::buildUri($server,$trustedHosts,$trustedProxies); $cookies=is_array($_COOKIE??null)?$_COOKIE:[]; $query=is_array($_GET??null)?$_GET:[]; $uploads=self::normalizeUploads($_FILES??[]);
            $input=fopen('php://input','rb'); if($input===false)throw new \RuntimeException('Unable to open request input stream.'); $body=new Stream($input);
            $contentType=strtolower(trim(explode(';',(string)($headers['content-type'][0]??''))[0]));
            $parsedBody=null; if($contentType==='application/x-www-form-urlencoded'||$contentType==='multipart/form-data') $parsedBody=is_array($_POST??null)&&$_POST!==[]?$_POST:null;
            return new ServerRequest($method,$uri,$server,$cookies,$query,$uploads,$parsedBody,$headers,$body,$protocol,self::requestTarget($server,$uri));
        }

        public static function decodeJsonBody(ServerRequestInterface $request,bool $associative=true):mixed
        {
            $body=$request->getBody(); $position=null; if($body->isSeekable()){$position=$body->tell();$body->rewind();}
            $raw=$body->getContents(); if($position!==null) $body->seek($position);
            if($raw==='') return null;
            try{$decoded=json_decode($raw,$associative,512,JSON_THROW_ON_ERROR);return $decoded;}catch(\JsonException $e){throw new \InvalidArgumentException('Malformed JSON request body.',0,$e);}
        }

        private static function buildUri(array $server,array $trustedHosts,array $trustedProxies):Uri
        {
            $requestUri=(string)($server['REQUEST_URI']??'/'); $parts=parse_url($requestUri); if($parts===false)throw new \InvalidArgumentException('Malformed REQUEST_URI.'); $path=(string)($parts['path']??'/'); $query=(string)($parts['query']??'');
            $remote=(string)($server['REMOTE_ADDR']??''); $trusted=self::isTrustedProxy($remote,$trustedProxies);
            $hostHeader=$trusted&&isset($server['HTTP_X_FORWARDED_HOST'])?(string)explode(',',$server['HTTP_X_FORWARDED_HOST'])[0]:(string)($server['HTTP_HOST']??$server['SERVER_NAME']??'');
            $parsedHost=parse_url('//'.$hostHeader); if($parsedHost===false)throw new \InvalidArgumentException('Malformed Host header.'); $host=(string)($parsedHost['host']??''); $forwardedPort=isset($parsedHost['port'])?(int)$parsedHost['port']:null;
            $scheme=!empty($server['HTTPS'])&&$server['HTTPS']!=='off'?'https':'http'; if($trusted&&isset($server['HTTP_X_FORWARDED_PROTO'])){$forwardedScheme=strtolower(trim(explode(',',(string)$server['HTTP_X_FORWARDED_PROTO'])[0]));if(!in_array($forwardedScheme,['http','https'],true))throw new \InvalidArgumentException('Invalid forwarded protocol.');$scheme=$forwardedScheme;}
            $port=$forwardedPort; if($port===null&&isset($server['SERVER_PORT'])){$p=(int)$server['SERVER_PORT'];if(($scheme==='http'&&$p!==80)||($scheme==='https'&&$p!==443))$port=$p;}
            $script=(string)($server['SCRIPT_NAME']??''); $scriptFilename=(string)($server['SCRIPT_FILENAME']??'');
            $scriptPath=$script!==''?(parse_url($script,PHP_URL_PATH)?:''):'';
            $scriptBase=$scriptPath!==''?basename($scriptPath):'';
            $filenameBase=$scriptFilename!==''?basename($scriptFilename):'';
            // In PHP's built-in server router mode, SCRIPT_NAME is the requested URI
            // while SCRIPT_FILENAME points to the router script. Only strip a front-controller
            // prefix when the two basenames actually identify the same script.
            $isFrontControllerScript=$scriptPath!==''&&$filenameBase!==''&&$scriptBase===$filenameBase;
            if($isFrontControllerScript){ if($path===$scriptPath)$path='/'; elseif(str_starts_with($path,$scriptPath.'/'))$path=substr($path,strlen($scriptPath)); if($path==='')$path='/'; }
            $base=$scheme.'://'.($host!==''?$host:'localhost').($port!==null?':'.$port:'').$path.($query!==''?'?'.$query:''); return new Uri($base,$trustedHosts);
        }
        private static function extractHeaders(array $server):array
        {
            $validator=new \Zef\Framework\Validation\HeaderValidator();$headers=[];
            foreach($server as $key=>$value){if(!is_string($value))continue;$name=null;if(str_starts_with($key,'HTTP_'))$name=str_replace('_','-',substr($key,5));elseif($key==='CONTENT_TYPE'||$key==='CONTENT_LENGTH')$name=str_replace('_','-',$key);if($name===null)continue;$validator->assertName($name);$validator->assertValue($name,$value);$headers[strtolower($name)]=[$value];}
            return $headers;
        }
        private static function normalizeUploads(array $files):array
        {
            $factory=new Psr17Factory();
            $build=function($name,$type,$tmp,$error,$size)use(&$build,$factory){
                if(is_array($error)){
                    $result=[]; foreach($error as $key=>$err){$result[$key]=$build($name[$key]??null,$type[$key]??null,$tmp[$key]??null,$err,$size[$key]??null);} return $result;
                }
                $error=(int)$error; $tmp=(string)($tmp??''); $stream=($error===UPLOAD_ERR_OK&&$tmp!=='')?$factory->createStreamFromFile($tmp,'rb'):$factory->createStream('');
                return $factory->createUploadedFile($stream,$size!==null?(int)$size:null,$error,is_string($name)?$name:null,is_string($type)?$type:null);
            };
            $out=[]; foreach($files as $field=>$spec){
                if(!is_array($spec)||!array_key_exists('error',$spec)){ $out[$field]=$spec; continue; }
                $out[$field]=$build($spec['name']??null,$spec['type']??null,$spec['tmp_name']??null,$spec['error'],$spec['size']??null);
            } return $out;
        }
        private static function protocolVersion(string $protocol):string{return preg_match('/^HTTP\/(\d+\.\d+)$/',$protocol,$m)===1?$m[1]:'1.1';}
        private static function requestTarget(array $server,\Psr\Http\Message\UriInterface $uri):string{$target=(string)($server['REQUEST_URI']??($uri->getPath()?:'/'));if(preg_match('/[\r\n]/',$target)===1)throw new \InvalidArgumentException('Invalid request target.');return $target;}
        private static function isTrustedProxy(string $remote,array $trusted):bool
        {
            if($remote==='')return false; foreach($trusted as $entry){$entry=trim((string)$entry); if($entry===$remote)return true; if(str_contains($entry,'/')){[$network,$bits]=array_pad(explode('/',$entry,2),2,null); if(filter_var($remote,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)&&filter_var($network,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){ $bits=(int)$bits;if($bits>=0&&$bits<=32){$mask=$bits===0?0:(-1 << (32-$bits)) & 0xFFFFFFFF; if((ip2long($remote)&$mask)===(ip2long($network)&$mask))return true;}} }} return false;
        }
    }
}

/* ================================================================
 * SECTION 15 — MIDDLEWARES
 * ================================================================ */
namespace Zef\Middleware {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\MiddlewareInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Config\ConfigProviderInterface;
    use Zef\Framework\Http\Response;

    /**
     *  Core code now depends on Psr\\Log\\LoggerInterface.
     * Retained temporarily for legacy modules that still reference this helper.
     */
    final class ErrorLogger
    {
        public function __construct(private readonly bool $includeMessage=false){}
        public function log(string $correlationId,\Throwable $e,ServerRequestInterface $request):void
        {
            $message=$this->sanitize($e->getMessage());
            $entry=['timestamp'=>date(DATE_ATOM),'level'=>'error','correlation_id'=>$correlationId,'method'=>$request->getMethod(),'path'=>$request->getUri()->getPath(),'exception'=>get_class($e)];
            if($this->includeMessage)$entry['message']=$message;
            $json=json_encode($entry,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            error_log($json===false?'ZEF error logging failure':$json);
        }
        private function sanitize(string $message):string
        {
            $message=preg_replace('/(password|passwd|token|secret|api[_-]?key)=([^\s,;]+)/i','$1=[REDACTED]',$message)??$message;
            return strlen($message)>2048?substr($message,0,2048).'…':$message;
        }
    }

    final class ErrorResponseFactory
    {
        public function __construct(private readonly bool $devMode=false){}
        public function isDebug():bool{return $this->devMode;}
        public function create(int $status,string $message,string $correlationId):Response{return new Response($status,['Content-Type'=>'application/json'],json_encode(['error'=>true,'status'=>$status,'message'=>$this->devMode?$message:'An error occurred','correlation_id'=>$correlationId],JSON_THROW_ON_ERROR));}
    }

    final class GlobalErrorHandler implements MiddlewareInterface
    {
        public function __construct(private readonly \Psr\Log\LoggerInterface $logger,private readonly ErrorResponseFactory $factory){}
        public function process(ServerRequestInterface $request,RequestHandlerInterface $handler):ResponseInterface
        {
            $correlationId=$request->getHeaderLine('X-Request-ID'); if($correlationId===''||strlen($correlationId)>128||preg_match('/^[A-Za-z0-9._:-]+$/',$correlationId)!==1)$correlationId=bin2hex(random_bytes(16));
            try{return $handler->handle($request)->withHeader('X-Request-ID',$correlationId);}
            catch(\Zef\Framework\Exception\MethodNotAllowedException $e){return $this->factory->create(405,'Method Not Allowed',$correlationId)->withHeader('Allow',implode(', ',$e->allowedMethods))->withHeader('X-Request-ID',$correlationId);}
            catch(\Throwable $e){
                try{
                    $this->logger->error('Unhandled application exception',[
                        'exception'=>$e,
                        'request_id'=>$correlationId,
                        'method'=>$request->getMethod(),
                        'uri'=>(string)$request->getUri(),
                    ]);
                }catch(\Throwable $loggingFailure){
                    @error_log('ZEF logging failure: '.get_class($loggingFailure));
                }
                try{return $this->factory->create(500,$this->factory->isDebug()?$e->getMessage():'Internal Server Error',$correlationId)->withHeader('X-Request-ID',$correlationId);}catch(\Throwable){return new Response(500,['Content-Type'=>'text/plain','X-Request-ID'=>$correlationId],'Internal Server Error');}
            }
        }
    }

    final class TimingMiddleware implements MiddlewareInterface
    {
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $startNs = hrtime(true);
            $response = $handler->handle($request);
            $elapsedNs = hrtime(true) - $startNs;
            $elapsedMs = round($elapsedNs / 1_000_000, 2);

            return $response->withHeader('X-Response-Time', $elapsedMs . 'ms');
        }
    }

    final class CorsMiddleware implements MiddlewareInterface
    {
        public function __construct(private readonly ?string $allowOrigin=null,private readonly string $allowMethods='GET, POST, PUT, PATCH, DELETE, OPTIONS',private readonly string $allowHeaders='Content-Type, Authorization, X-Request-ID'){}
        public function process(ServerRequestInterface $request,RequestHandlerInterface $handler):ResponseInterface
        {
            if($this->allowOrigin===null || $this->allowOrigin==='') return $handler->handle($request);
            if($request->getMethod()==='OPTIONS') return new Response(204,['Access-Control-Allow-Origin'=>$this->allowOrigin,'Access-Control-Allow-Methods'=>$this->allowMethods,'Access-Control-Allow-Headers'=>$this->allowHeaders,'Vary'=>'Origin']);
            $response=$handler->handle($request)->withHeader('Access-Control-Allow-Origin',$this->allowOrigin)->withHeader('Access-Control-Allow-Methods',$this->allowMethods)->withHeader('Access-Control-Allow-Headers',$this->allowHeaders);$vary=$response->getHeader('Vary');$tokens=[];foreach($vary as $line){foreach(explode(',',$line) as $token){$token=trim($token);if($token!=='')$tokens[strtolower($token)]=$token;}}$tokens['origin']='Origin';return $response->withHeader('Vary',implode(', ',array_values($tokens)));
        }
    }

    final class SecurityHeadersMiddleware implements MiddlewareInterface
    {
        public function process(ServerRequestInterface $request,RequestHandlerInterface $handler):ResponseInterface{return $handler->handle($request)->withHeader('X-Content-Type-Options','nosniff')->withHeader('X-Frame-Options','DENY')->withHeader('Referrer-Policy','no-referrer');}
    }

    final class ConfigProvider implements ConfigProviderInterface
    {
        public function __construct(private readonly bool $devMode=false){}
        public function getModuleName():string{return 'middleware';}
        public function getConfig():array{$devMode=$this->devMode;return ['services'=>[
            'middleware.error'=>['factory'=>static function(\Psr\Container\ContainerInterface $c) use ($devMode): GlobalErrorHandler { return new GlobalErrorHandler($c->get(\Psr\Log\LoggerInterface::class),new ErrorResponseFactory($devMode)); },'deps'=>[\Psr\Log\LoggerInterface::class]],
            'middleware.timing'=>['factory'=>static fn()=>new TimingMiddleware(),'deps'=>[]],
            'middleware.cors'=>['factory'=>function(){ $origin=getenv('ZEF_CORS_ORIGIN'); $origin=($origin!==false && trim((string)$origin)!=='')?trim((string)$origin):null; return new CorsMiddleware($origin); },'deps'=>[]],
            'middleware.security'=>['factory'=>static fn()=>new SecurityHeadersMiddleware(),'deps'=>[]],
        ],'stack'=>['middleware.error','middleware.security','middleware.timing','middleware.cors']];}
    }
}

/* ================================================================
 * SECTION 16 — SAMPLE CORE MODULE
 * ================================================================ */
namespace Zef\Module\Core {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Config\ConfigProviderInterface;
    use Zef\Framework\Http\Response;

    final class HomeHandler implements RequestHandlerInterface{public function handle(ServerRequestInterface $request):ResponseInterface{return new Response(200,['Content-Type'=>'application/json'],json_encode(['module'=>'core','page'=>'home','framework'=>'ZEF Framework v'.\Zef\Framework\Foundation\ZefVersion::VERSION,'psr'=>['container'=>true,'http-message'=>true,'http-server-handler'=>true,'http-server-middleware'=>true,'http-factory'=>true]],JSON_THROW_ON_ERROR));}}
    final class AboutHandler implements RequestHandlerInterface{public function handle(ServerRequestInterface $request):ResponseInterface{return new Response(200,['Content-Type'=>'application/json'],json_encode(['module'=>'core','page'=>'about','version'=>\Zef\Framework\Foundation\ZefVersion::VERSION,'php'=>PHP_VERSION,'license'=>'MIT'],JSON_THROW_ON_ERROR));}}
    final class ConfigProvider implements ConfigProviderInterface
    {public function getModuleName():string{return 'core';} public function getConfig():array{return ['services'=>['core.handler.home'=>['factory'=>static fn()=>new HomeHandler(),'deps'=>[]],'core.handler.about'=>['factory'=>static fn()=>new AboutHandler(),'deps'=>[]]],'routes'=>[['method'=>'GET','path'=>'/','handler'=>'core.handler.home','priority'=>100],['method'=>'GET','path'=>'/about','handler'=>'core.handler.about','priority'=>100]]];}}
}

/* ================================================================
 * SECTION 17 — SAMPLE STORE PLUGIN
 * ================================================================ */
namespace Zef\Plugin\Toko {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Config\ConfigProviderInterface;
    use Zef\Framework\Http\Response;
    use Zef\Framework\Container\ServiceLifetime;

    final class ProdukService
    {
        private array $produk=[['id'=>1,'nama'=>'Keyboard Mechanical','harga'=>450000],['id'=>2,'nama'=>'Mouse Wireless','harga'=>185000],['id'=>3,'nama'=>'Monitor 27"','harga'=>3200000]];
        public function all():array{return $this->produk;} public function find(int $id):?array{foreach($this->produk as $item)if($item['id']===$id)return $item;return null;}
    }
    final class TokoHandler implements RequestHandlerInterface{public function __construct(private readonly ProdukService $service){}public function handle(ServerRequestInterface $request):ResponseInterface{return new Response(200,['Content-Type'=>'application/json'],json_encode(['module'=>'toko','page'=>'index','produk'=>$this->service->all()],JSON_THROW_ON_ERROR));}}
    final class ProdukDetailHandler implements RequestHandlerInterface{public function __construct(private readonly ProdukService $service){}public function handle(ServerRequestInterface $request):ResponseInterface{$id=(int)$request->getAttribute('id',0);$p=$this->service->find($id);if($p===null)return new Response(404,['Content-Type'=>'application/json'],json_encode(['error'=>"Produk #{$id} tidak ditemukan."],JSON_THROW_ON_ERROR));return new Response(200,['Content-Type'=>'application/json'],json_encode(['module'=>'toko','page'=>'detail','produk'=>$p],JSON_THROW_ON_ERROR));}}
    final class ConfigProvider implements ConfigProviderInterface{public function getModuleName():string{return 'toko';}public function getConfig():array{return ['services'=>['toko.service.produk'=>['factory'=>static fn()=>new ProdukService(),'deps'=>[],'lifetime'=>ServiceLifetime::SINGLETON],'toko.handler.index'=>['factory'=>static fn(\Psr\Container\ContainerInterface $c,ProdukService $svc)=>new TokoHandler($svc),'deps'=>['toko.service.produk'],'lifetime'=>ServiceLifetime::SINGLETON],'toko.handler.detail'=>['factory'=>static fn(\Psr\Container\ContainerInterface $c,ProdukService $svc)=>new ProdukDetailHandler($svc),'deps'=>['toko.service.produk'],'lifetime'=>ServiceLifetime::SINGLETON]],'aliases'=>['toko.produk'=>'toko.service.produk'],'routes'=>[['method'=>'GET','path'=>'/toko','handler'=>'toko.handler.index','priority'=>100],['method'=>'GET','path'=>'/toko/produk/{id:int}','handler'=>'toko.handler.detail','priority'=>100]]];}}
}

/* ================================================================
 * SECTION 18 — APPLICATION BOOTSTRAP
 * ================================================================
 */
namespace Zef\App {
    use Zef\Framework\Application;
    use Zef\Middleware\ConfigProvider as MiddlewareConfigProvider;
    use Zef\Module\Core\ConfigProvider as CoreConfigProvider;
    use Zef\Plugin\Toko\ConfigProvider as TokoConfigProvider;

    final class Bootstrap
    {
        public static function createApp(bool $debug=false, ?\Psr\Log\LoggerInterface $logger=null):Application
        {
            $app=new Application($debug,$logger);
            $trusted=getenv('ZEF_TRUSTED_HOSTS'); $hosts=$trusted!==false&&trim($trusted)!==''?array_map('trim',explode(',',(string)$trusted)):['localhost','127.0.0.1','::1','zef.test']; $app->setTrustedHosts($hosts);
            $app->addProvider(new MiddlewareConfigProvider($debug));$app->addProvider(new CoreConfigProvider());$app->addProvider(new TokoConfigProvider()); return $app;
        }
    }
}

/* ================================================================
 * SECTION 19 — SELF TEST
 * ================================================================ */
namespace Zef\Test {
    use Zef\App\Bootstrap;
    use Zef\Framework\Application;
    use Zef\Framework\Container\Container;
    use Zef\Framework\Container\ServiceLifetime;
    use Zef\Framework\Http\RequestFactory;
    use Zef\Framework\Http\Response;
    use Zef\Framework\Http\ServerRequest;
    use Zef\Framework\Http\Uri;
        use Zef\Framework\Router\Router;
    use Zef\Framework\MiddlewarePipeline;
    use Zef\Plugin\Toko\ProdukService;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\RequestHandlerInterface;

    final class CliRunner
    {
        private int $passed=0; private int $failed=0;
        public function run(bool $html=false):int
        {
            $this->banner($html);
            $this->suite('PSR contracts', fn()=> $this->testPsr());
            $this->suite('Application routes', fn()=> $this->testRoutes());
            $this->suite('Container lifetime/cycles', fn()=> $this->testContainer());
            $this->suite('Concurrent request scope isolation', fn()=> $this->testConcurrencyScopes());
            $this->suite('Security/header/URI', fn()=> $this->testSecurity());
            $this->suite('Request/factory boundaries', fn()=> $this->testRequestFactoryBoundaries());
            $this->suite('Router ambiguity', fn()=> $this->testRouterSemantics());
            $this->suite('Pipeline/error boundary', fn()=> $this->testPipeline());
            $this->suite('PSR-7 edge cases & resource safety', fn()=> $this->testPsrHardening());
            $this->suite('Final production hardening', fn()=> $this->testFinalHardening());
            $this->suite('JSON scalar boundary', fn()=> $this->testJsonScalar());
            $this->suite('Zero critical bugs gate', fn()=> $this->testZeroCriticalGate());
            $this->summary($html);
            return $this->failed===0 ? 0 : 1;
        }
        private function testPsr():void{$this->ok(is_subclass_of(Response::class,ResponseInterface::class),'Response implements PSR-7 ResponseInterface');$this->ok(is_subclass_of(ServerRequest::class,ServerRequestInterface::class),'ServerRequest implements PSR-7 ServerRequestInterface');$c=new \Zef\Framework\Http\Psr17Factory();$this->ok($c->createResponse() instanceof ResponseInterface,'PSR-17 response factory');$this->ok($c->createStream('abc')->__toString()==='abc','PSR-17 stream factory');$msg=(new Response(200,['X-Test'=>'one']))->withHeader('x-test','two');$this->ok($msg->getHeaderLine('X-TEST')==='two' && array_key_exists('x-test',$msg->getHeaders()),'case-insensitive header replacement preserves supplied case');$added=$msg->withAddedHeader('X-TEST','three');$this->ok($added->getHeaderLine('x-test')==='two, three' && count($added->getHeaders())===1,'case-insensitive withAddedHeader cannot create duplicate keys');$this->ok($added->withoutHeader('x-Test')->getHeaders()===[],'header dictionary removes canonical key');}
        private function testRoutes():void{$app=Bootstrap::createApp(false);$app->boot();foreach([['GET','/',200],['GET','/about',200],['GET','/toko',200],['GET','/toko/produk/2',200],['GET','/missing',404],['GET','/toko/produk/abc',400]] as [$m,$p,$s]){$r=$app->handle(new ServerRequest($m,new Uri('http://localhost'.$p,['localhost'])));$this->ok($r->getStatusCode()===$s,"{$m} {$p} -> {$s}");}$r=$app->handle(new ServerRequest('GET',new Uri('http://localhost/toko/produk/2',['localhost'])));$this->ok(str_contains($r->bodyString(),'Mouse Wireless'),'detail body');$this->ok($r->hasHeader('x-request-id'),'correlation ID');$this->ok($r->hasHeader('x-content-type-options'),'security middleware');}
        private function testContainer():void{$c=new Container();$c->register('a',static fn()=>new \stdClass(),[],'m',ServiceLifetime::SINGLETON);$c->register('b',static fn($x,$a)=>new \stdClass(),['a'],'m',ServiceLifetime::REQUEST);$c->validateAndFreeze();$s1=$c->createRequestScope();$b1=$s1->get('b');$this->ok($s1->get('b')===$b1,'request scope singleton within scope');$s1->close();$s2=$c->createRequestScope();$this->ok($s2->get('b')!==$b1,'request scope cleared between scopes');$s2->close();$this->ok($c->get('a')===$c->get('a'),'singleton stable');$cycle=new Container();$cycle->register('x',static fn($c,$y)=>new \stdClass(),['y'],'m');$cycle->register('y',static fn($c,$x)=>new \stdClass(),['x'],'m');$this->throws(\Zef\Framework\Exception\ServiceCircularDependencyException::class,fn()=> $cycle->validateAndFreeze(),'boot dependency cycle');}
        private function testConcurrencyScopes():void
        {
            $c=new Container();
            $c->register('request.counter',static fn()=>new \stdClass(),[], 'test', ServiceLifetime::REQUEST);
            $c->register('singleton.bad',static fn($c,$x)=>new \stdClass(),['request.counter'],'test', ServiceLifetime::SINGLETON);
            $this->throws(\Zef\Framework\Exception\InvalidConfigurationException::class,fn()=> $c->validateAndFreeze(),'captive request dependency rejected');

            $c2=new Container();
            $c2->register('request.counter',static fn()=>new \stdClass(),[], 'test', ServiceLifetime::REQUEST);
            $c2->validateAndFreeze();
            $a=$c2->createRequestScope();$b=$c2->createRequestScope();
            $a1=$a->get('request.counter');$a2=$a->get('request.counter');$b1=$b->get('request.counter');
            $this->ok($a1===$a2,'request scope stable within scope');
            $this->ok($a1!==$b1,'independent request scopes do not share instances');
            $a->close();$b->close();
        }

        private function testSecurity():void{$r=new Response();$this->throws(\InvalidArgumentException::class,fn()=> $r->withHeader("X-Bad\r\nX-Evil",'x'),'header-name CRLF blocked');$this->throws(\InvalidArgumentException::class,fn()=> $r->withHeader('X-Bad',"x\r\ny"),'header-value CRLF blocked');$u=new Uri('http://localhost/a',['localhost']);$this->ok($u->withPath('/x/y')->getPath()==='/x/y','URI immutable path');$this->throws(\InvalidArgumentException::class,fn()=>new Uri('http://evil.test/',['localhost']),'trusted host enforced');$this->throws(\InvalidArgumentException::class,fn()=> $u->withPort(70000),'port range');$rv=new \Zef\Framework\Validation\RouteConstraintValidator();$this->throws(\Zef\Framework\Exception\InvalidConfigurationException::class,fn()=> $rv->addCustom('broken','/^[0-9/'),'malformed custom regex rejected without warning leakage');}
        private function testPsrHardening():void
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
            $this->throws(\InvalidArgumentException::class, fn() => $parsed->withParsedBody('invalid'), 'PSR-7: scalar parsed body rejected');

            $resource = fopen('php://temp', 'w+b');
            fwrite($resource, 'emit-close-contract');
            $stream = $factory->createStreamFromResource($resource);
            $response = $factory->createResponse(200)->withBody($stream);
            ob_start();
            (new \Zef\Framework\ResponseEmitter())->emit($response);
            ob_end_clean();
            $this->ok($stream->isReadable(), 'Emitter: borrowed stream remains open by default');

            $resource2 = fopen('php://temp', 'w+b');
            fwrite($resource2, 'explicit-close');
            $stream2 = $factory->createStreamFromResource($resource2);
            $response2 = $factory->createResponse(200)->withBody($stream2);
            ob_start();
            (new \Zef\Framework\ResponseEmitter())->emit($response2, true);
            ob_end_clean();
            $this->throws(\RuntimeException::class, fn() => $stream2->tell(), 'Emitter: explicit close invalidates stream deterministically');

            $agg = new \Zef\Framework\Config\ConfigAggregator();
            $provider = new class implements \Zef\Framework\Config\ConfigProviderInterface {
                public function getModuleName(): string { return 'core'; }
                public function getConfig(): array { return []; }
            };
            $provider2 = new class implements \Zef\Framework\Config\ConfigProviderInterface {
                public function getModuleName(): string { return 'Core'; }
                public function getConfig(): array { return []; }
            };
            $agg->addProvider($provider);
            $this->throws(\Zef\Framework\Exception\InvalidConfigurationException::class, fn() => $agg->addProvider($provider2), 'Config: module collision is case-insensitive');
        }

        private function testRequestFactoryBoundaries():void
        {
            $factory=new \Zef\Framework\Http\Psr17Factory();
            $req=$factory->createRequest('GET','http://localhost/demo');
            $this->ok($req->getHeaderLine('Host')==='localhost','request factory populates Host from URI');
            $this->ok($req->withMethod('PATCH')->getMethod()==='PATCH','HTTP method case preserved');
            $this->ok($req->getRequestTarget()==='/demo','request target defaults to origin-form');
            $retargeted=$req->withUri($factory->createUri('http://localhost/other?q=1'));
            $this->ok($retargeted->getRequestTarget()==='/other?q=1','withUri updates derived request target');
            $explicit=$req->withRequestTarget('*')->withUri($factory->createUri('http://localhost/other'));
            $this->ok($explicit->getRequestTarget()==='*','explicit request target survives withUri');
            $emptyTarget=$req->withRequestTarget('');
            $this->ok($emptyTarget->getRequestTarget()==='','explicit empty request target retained verbatim');
            $this->throws(\InvalidArgumentException::class,fn()=> $factory->createStreamFromFile('/does/not/exist','q'),'invalid stream mode rejected');

            $container=new Container();
            $container->register('request.s',fn()=>new \stdClass(),[],'t',ServiceLifetime::REQUEST);
            $container->validateAndFreeze();
            $a=$container->createRequestScope();$b=$container->createRequestScope();
            $aa=$a->get('request.s');$bb=$b->get('request.s');
            $this->ok($aa!==$bb,'independent request scopes do not share mutable state');
            $a->close();$b->close();
        }

        private function testRouterSemantics():void{$r=new Router();$r->add('GET','/user/{id:int}','dynamic',priority:0);$r->add('GET','/user/admin','static',priority:10);$m=$r->match('GET','/user/admin');$this->ok($m['handler']==='static','static route wins over constraint mismatch');$this->throws(\Zef\Framework\Exception\RouteConstraintException::class,fn()=> $r->match('GET','/user/abc'),'constraint failure produces 400-class exception');}
        private function testPipeline():void{$terminal=new class implements RequestHandlerInterface{public function handle(ServerRequestInterface $r):ResponseInterface{throw new \RuntimeException('boom');}};$mw=new \Zef\Middleware\GlobalErrorHandler(new \Psr\Log\NullLogger(),new \Zef\Middleware\ErrorResponseFactory(false));$p=(new MiddlewarePipeline([], $terminal))->withMiddleware($mw);$res=$p->handle(new ServerRequest('GET',new Uri('http://localhost/',['localhost'])));$this->ok($res->getStatusCode()===500,'error boundary catches terminal exception');}
        private function testFinalHardening():void
        {
            // URI scheme/control and IPv6 authority
            $u=new Uri('http://localhost/',['localhost','::1']);
            $this->throws(\InvalidArgumentException::class,fn()=> $u->withScheme("http\r\n"),'URI scheme rejects control chars');
            $ipv6=$u->withHost('[::1]');
            $this->ok($ipv6->getHost()==='::1' && $ipv6->getAuthority()==='[::1]','IPv6 host authority normalized');
            $relative=new Uri('foo/bar');
            $this->ok($relative->getPath()==='foo/bar' && (string)$relative==='foo/bar','URI rootless path preserved per PSR-7');
            $this->ok((new \Zef\Framework\Http\Request('GET',$relative))->getRequestTarget()==='/foo/bar','request target derives origin-form from rootless URI');
            $encoded=(new Uri())->withPath('/hello world')->withQuery('q=a b');
            $this->ok($encoded->getPath()==='/hello%20world' && $encoded->getQuery()==='q=a%20b','URI components are percent-encoded');

            // Stream cursor is restored by string conversion.
            $st=\Zef\Framework\Http\Stream::fromString('abcdef');$st->seek(2);$this->ok((string)$st==='abcdef' && $st->tell()===6,'Stream::__toString__ rewinds and reads to EOF per PSR-7');

            // Uploaded file failure must remain retryable and must remove partial destination.
            $upStream=\Zef\Framework\Http\Stream::fromString('upload'); $up=new \Zef\Framework\Http\UploadedFile($upStream); $bad=sys_get_temp_dir().'/zef-missing-dir/'.bin2hex(random_bytes(4)); $this->throws(\RuntimeException::class,fn()=> $up->moveTo($bad),'upload move failure does not poison object');
            $this->ok(!$upStream->eof(),'source stream remains usable after failed move');

            // Transitive captive dependency prevention.
            $c=new Container();$c->register('req',static fn()=>new \stdClass(),[],'m',ServiceLifetime::REQUEST);$c->register('mid',static fn($c,$r)=>new \stdClass(),['req'],'m',ServiceLifetime::SINGLETON);$c->register('top',static fn($c,$m)=>new \stdClass(),['mid'],'m',ServiceLifetime::SINGLETON);$this->throws(\Zef\Framework\Exception\InvalidConfigurationException::class,fn()=> $c->validateAndFreeze(),'transitive captive dependency rejected');

            // Router structural duplicate and 405 semantics.
            $r=new Router();$r->add('GET','/user/{id:int}','a');$this->throws(\InvalidArgumentException::class,fn()=> $r->add('GET','/user/{name:int}','b'),'structural dynamic route collision rejected');$r->add('POST','/user/{id:int}','p');$this->throws(\Zef\Framework\Exception\MethodNotAllowedException::class,fn()=> $r->match('PUT','/user/7'),'405 route method mismatch detected');

            // Scope closed boundary.
            $sc=$c2=new Container();$sc->register('r',static fn()=>new \stdClass(),[],'m',ServiceLifetime::REQUEST);$sc->validateAndFreeze();$scope=$sc->createRequestScope();$scope->close();$this->throws(\LogicException::class,fn()=> $scope->get('r'),'closed request scope rejects access');
            $corsOff=new \Zef\Middleware\CorsMiddleware(null);
            $corsReq=new ServerRequest('GET',new Uri('http://localhost/',['localhost']));
            $corsRes=$corsOff->process($corsReq,new class implements RequestHandlerInterface{public function handle(ServerRequestInterface $r):ResponseInterface{return new Response(200,[],"ok");}});
            $this->ok(!$corsRes->hasHeader('Access-Control-Allow-Origin'),'CORS disabled by default');
            $corsOn=new \Zef\Middleware\CorsMiddleware('https://example.test');
            $corsRes2=$corsOn->process($corsReq,new class implements RequestHandlerInterface{public function handle(ServerRequestInterface $r):ResponseInterface{return new Response(200,['Vary'=>'Accept-Encoding'],"ok");}});
            $this->ok($corsRes2->getHeaderLine('Access-Control-Allow-Origin')==='https://example.test' && $corsRes2->getHeaderLine('Vary')==='Accept-Encoding, Origin','CORS opt-in merges existing Vary');
        }

        private function testJsonScalar():void
        {
            $factory=new \Zef\Framework\Http\Psr17Factory();
            $req=$factory->createServerRequest('POST','http://localhost/',['CONTENT_TYPE'=>'application/json']);
            $stream=$factory->createStream('123');$req=$req->withBody($stream);
            $this->ok(\Zef\Framework\Http\RequestFactory::decodeJsonBody($req)===123,'JSON scalar remains available through explicit decoder');
            $this->throws(\InvalidArgumentException::class,fn()=> $req->withParsedBody(123),'PSR parsedBody contract still rejects scalar');
        }

        private function testZeroCriticalGate():void
        {
            // PSR-11 has() must be true for registered request services even outside scope.
            $c=new \Zef\Framework\Container\Container();
            $c->register('req',static fn()=>new \stdClass(),[],'gate',\Zef\Framework\Container\ServiceLifetime::REQUEST);
            $c->validateAndFreeze();
            $this->ok($c->has('req')===true,'PSR-11 has() reports registered request service');
            $this->throws(\Psr\Container\ContainerExceptionInterface::class,fn()=> $c->get('req'),'request service outside scope is a ContainerException');

            // URI malformed percent escapes must be encoded, not emitted verbatim.
            $badPct=(new \Zef\Framework\Http\Uri())->withPath('/x%ZZ');
            $this->ok($badPct->getPath()==='/x%25ZZ','malformed percent escape is encoded');
            $this->throws(\InvalidArgumentException::class,fn()=> new \Zef\Framework\Http\Request('',$badPct),'empty request method rejected');

            // Protocol constructor validation.
            $this->throws(\InvalidArgumentException::class,fn()=> new \Zef\Framework\Http\Response(200,[], '', '', 'not-http'),'invalid protocol rejected in constructor');

            // Route custom constraints are validated at registration, not first request.
            $r=new \Zef\Framework\Router\Router();
            $this->throws(\Zef\Framework\Exception\InvalidConfigurationException::class,fn()=> $r->add('GET','/x/{id:missing}','h'),'unknown route constraint rejected during registration');
            $r->addConstraint('digits','/^\\d+$/');
            $r->add('GET','/x/{id:digits}','h');
            $this->throws(\InvalidArgumentException::class,fn()=> $r->add('GET','/x/{other:digits}','h2'),'structural duplicate remains rejected');

            // CORS Vary de-duplication.
            $cors=new \Zef\Middleware\CorsMiddleware('https://example.test');
            $res=$cors->process(new \Zef\Framework\Http\ServerRequest('GET',new \Zef\Framework\Http\Uri('http://localhost/',['localhost'])),new class implements \Psr\Http\Server\RequestHandlerInterface{public function handle(\Psr\Http\Message\ServerRequestInterface $r):\Psr\Http\Message\ResponseInterface{return new \Zef\Framework\Http\Response(200,['Vary'=>'Accept-Encoding, Origin'],'ok');}});
            $this->ok($res->getHeaderLine('Vary')==='Accept-Encoding, Origin','CORS does not duplicate Vary token');

            // Scope context is reset after close and singleton warm-up is deterministic.
            $sc=$c->createRequestScope();$first=$sc->get('req');$sc->close();$this->throws(\LogicException::class,fn()=> $sc->get('req'),'closed scope rejects resolution');
        }

        private function suite(string $name,callable $test):void{try{$test();$this->out("[PASS] {$name}");}catch(\Throwable $e){$this->failed++;$this->out("[FAIL] {$name}: {$e->getMessage()}");}}
        private function ok(bool $cond,string $label):void{if($cond){$this->passed++;$this->out('  ✔ '.$label);}else{$this->failed++;$this->out('  ✘ '.$label);}}
        private function throws(string $class,callable $fn,string $label):void{try{$fn();$this->ok(false,$label.' (no exception)');}catch(\Throwable $e){$this->ok($e instanceof $class,$label.' -> '.get_class($e));}}
        private function out(string $line):void{echo $line.(PHP_SAPI==='cli'?"\n":"<br>\n");}
        private function banner(bool $html):void{if($html){echo '<!doctype html><html><head><meta charset="utf-8"><title>ZEF self-test</title><style>body{font-family:system-ui;background:#111;color:#eee;padding:24px}pre{white-space:pre-wrap}</style></head><body><pre>'; } echo "ZEF Framework v2.5.0-alpha4 — SELF TEST\n";}
        private function summary(bool $html):void{$this->out("\nPASSED: {$this->passed}  FAILED: {$this->failed}"); if($html)echo '</pre></body></html>';$GLOBALS['zef_test_summary']=['passed'=>$this->passed,'failed'=>$this->failed];}
    }
}

/* ================================================================
 * SECTION 20 — BROWSER/CLI ENTRY POINT
 * ================================================================ */
namespace {
    if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
            if (PHP_VERSION_ID < 80100) {
                fwrite(STDERR, "ZEF Framework v".\Zef\Framework\Foundation\ZefVersion::VERSION." requires PHP >= 8.1\n");
                exit(1);
            }

            $debug = filter_var(getenv('ZEF_DEBUG') ?: '0', FILTER_VALIDATE_BOOL);

            if (PHP_SAPI === 'cli') {
                $args = $_SERVER['argv'] ?? [];
                if (in_array('--self-test', $args, true)) {
                    exit((new \Zef\Test\CliRunner())->run(false));
                }
                fwrite(STDOUT, "ZEF Framework v".\Zef\Framework\Foundation\ZefVersion::VERSION."\nRun with --self-test for diagnostics.\n");
                exit(0);
            }

            try {
                $app = \Zef\App\Bootstrap::createApp($debug);
                if (isset($_GET['selftest']) && $_GET['selftest'] === '1') {
                    exit((new \Zef\Test\CliRunner())->run(true));
                }
                $response = $app->handleGlobals();
                $app->emit($response);
            } catch (\Throwable $e) {
                if (!headers_sent()) http_response_code(500);
                if ($debug) {
                    header('Content-Type: text/plain; charset=utf-8');
                    echo get_class($e).": ".$e->getMessage()."\n\n".$e->getTraceAsString();
                } else {
                    header('Content-Type: text/plain; charset=utf-8');
                    echo 'Internal Server Error';
                }
            }
    }
}
