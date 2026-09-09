<?php

declare(strict_types=1);

namespace Zef\Framework\Http {
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Message\StreamInterface;
    use Psr\Http\Message\UriInterface;

    final class ServerRequest extends MessageBase implements ServerRequestInterface
    {
        private ?string $requestTargetOverride;
        private string $method;
        private UriInterface $uri;
        /** @var array<array-key, mixed>|object|null */
        private mixed $parsedBody;

        /**
         * @param array<string, mixed>        $serverParams
         * @param array<string, string>        $cookieParams
         * @param array<string, mixed>         $queryParams
         * @param array<string, mixed>         $uploadedFiles
         * @param array<string, string|string[]> $headers
         * @param array<string, mixed>        $attributes
         */
        public function __construct(
            string $method,
            UriInterface $uri,
            private array $serverParams = [],
            private array $cookieParams = [],
            private array $queryParams = [],
            private array $uploadedFiles = [],
            mixed $parsedBody = null,
            array $headers = [],
            ?StreamInterface $body = null,
            string $protocolVersion = '1.1',
            string $requestTarget = '',
            private array $attributes = [],
        ) {
            if (!is_array($parsedBody) && !is_object($parsedBody) && $parsedBody !== null) {
                throw new \InvalidArgumentException('Parsed body must be array, object or null.');
            }
            parent::__construct($body, $headers, $protocolVersion);
            if (!$this->hasHeader('Host') && $uri->getHost() !== '') {
                $host = $uri->getHost();
                if ($uri->getPort() !== null) {
                    $host .= ':' . $uri->getPort();
                }
                $this->addInitialHeader('Host', $host);
            }
            \Zef\Framework\Validation\HttpMethodValidator::assert($method);
            $this->method = strtoupper($method);
            $this->uri = $uri;
            $this->parsedBody = $parsedBody;
            $this->requestTargetOverride = $requestTarget !== '' ? $requestTarget : null;
        }

        private function deriveRequestTarget(UriInterface $uri): string
        {
            $path = $uri->getPath();
            $target = $path === '' ? '/' : ($path[0] === '/' ? $path : '/' . $path);
            if ($uri->getQuery() !== '') {
                $target .= '?' . $uri->getQuery();
            }
            return $target;
        }

        #[\Override]
        public function getRequestTarget(): string
        {
            return $this->requestTargetOverride ?? $this->deriveRequestTarget($this->uri);
        }
        #[\Override]
        public function withRequestTarget(string $requestTarget): ServerRequestInterface
        {
            if (preg_match('/[\x00-\x1F\x7F]/', $requestTarget) === 1) {
                throw new \InvalidArgumentException('Invalid request target.');
            } $n = clone $this;
            $n->requestTargetOverride = $requestTarget;
            return $n;
        }
        #[\Override]
        public function getMethod(): string
        {
            return $this->method;
        }
        #[\Override]
        public function withMethod(string $method): ServerRequestInterface
        {
            if ($method === '' || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $method) !== 1) {
                throw new \InvalidArgumentException('Invalid HTTP method.');
            } $n = clone $this;
            $n->method = $method;
            return $n;
        }
        #[\Override]
        public function getUri(): UriInterface
        {
            return $this->uri;
        }
        #[\Override]
        public function withUri(UriInterface $uri, bool $preserveHost = false): ServerRequestInterface
        {
            $n = clone $this;
            $n->uri = $uri;
            $existingHost = $this->getHeaderLine('Host');
            if (!$preserveHost || $existingHost === '') {
                if ($uri->getHost() !== '') {
                    $host = $uri->getHost();
                    if ($uri->getPort() !== null) {
                        $host .= ':' . $uri->getPort();
                    }
                    /** @var static $n */
                    $n = $n->withHeader('Host', $host);
                }
            }
            return $n;
        }
        /** @return array<string, mixed> */
        #[\Override]
        public function getServerParams(): array
        {
            return $this->serverParams;
        }

        /** @return array<string, string> */
        #[\Override]
        public function getCookieParams(): array
        {
            return $this->cookieParams;
        }

        /** @param array<string, string> $cookies */
        #[\Override]
        public function withCookieParams(array $cookies): ServerRequestInterface
        {
            $n = clone $this;
            $n->cookieParams = $cookies;
            return $n;
        }

        /** @return array<string, mixed> */
        #[\Override]
        public function getQueryParams(): array
        {
            return $this->queryParams;
        }

        /** @param array<string, mixed> $query */
        #[\Override]
        public function withQueryParams(array $query): ServerRequestInterface
        {
            $n = clone $this;
            $n->queryParams = $query;
            return $n;
        }

        /** @return array<string, mixed> */
        #[\Override]
        public function getUploadedFiles(): array
        {
            return $this->uploadedFiles;
        }

        /** @param array<string, mixed> $uploadedFiles */
        #[\Override]
        public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
        {
            $this->assertUploadedTree($uploadedFiles);
            $n = clone $this;
            $n->uploadedFiles = $uploadedFiles;
            return $n;
        }

        /** @param array<array-key, mixed> $tree */
        private function assertUploadedTree(array $tree): void
        {
            foreach ($tree as $value) {
                if ($value instanceof \Psr\Http\Message\UploadedFileInterface) {
                    continue;
                } if (is_array($value)) {
                    $this->assertUploadedTree($value);
                    continue;
                } throw new \InvalidArgumentException('Uploaded files must contain only UploadedFileInterface leaves.');
            }
        }
        /** @return array<array-key, mixed>|object|null */
        #[\Override]
        public function getParsedBody(): mixed
        {
            return $this->parsedBody;
        }

        /** @param array<array-key, mixed>|object|null $data */
        #[\Override]
        public function withParsedBody($data): ServerRequestInterface
        {
            $this->assertParsedBodyShape($data);
            $n = clone $this;
            $n->parsedBody = $data;
            return $n;
        }

        /**
         * Runtime guard kept outside the PHPDoc-narrowed signature so the
         * check stays meaningful to static analysis (PSR-7 callers may pass
         * any scalar; the contract is enforced at runtime, not by the type).
         */
        private function assertParsedBodyShape(mixed $data): void
        {
            if (!is_array($data) && !is_object($data) && $data !== null) {
                throw new \InvalidArgumentException('Parsed body must be array, object or null.');
            }
        }
        /** @return array<string, mixed> */
        #[\Override]
        public function getAttributes(): array
        {
            return $this->attributes;
        }

        /** @param string $name */
        #[\Override]
        public function getAttribute(string $name, mixed $default = null): mixed
        {
            return $this->attributes[$name] ?? $default;
        }
        #[\Override]
        public function withAttribute(string $name, mixed $value): ServerRequestInterface
        {
            $n = clone $this;
            $n->attributes[$name] = $value;
            return $n;
        }
        #[\Override]
        public function withoutAttribute(string $name): ServerRequestInterface
        {
            $n = clone $this;
            unset($n->attributes[$name]);
            return $n;
        }
    }

}
