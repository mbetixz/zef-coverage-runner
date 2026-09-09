<?php

declare(strict_types=1);

namespace Zef\Framework\Http {
    use Psr\Http\Message\RequestInterface;
    use Psr\Http\Message\StreamInterface;
    use Psr\Http\Message\UriInterface;

    /**
     * Immutable PSR-7 request (RF-02.4 header normalization core).
     *
     * - METHOD is validated as an RFC 7230 token (non-empty, no separators /
     *   CTLs) at construction; withMethod() re-validates via
     *   HttpMethodValidator::assert().
     * - HOST header: when the request is built with a URI that has a host and
     *   no Host header was supplied, a Host header is derived from the URI
     *   authority (host + non-null port) through addInitialHeader() — so the
     *   Host header and the request URI never disagree at construction.
     * - REQUEST TARGET: default (no override) is origin-form — path (empty
     *   path becomes '/'; a leading '/' is added when missing) plus '?' +
     *   query when non-empty. withRequestTarget() rejects CTLs/whitespace so
     *   a raw attacker-controlled target cannot smuggle a second request line.
     * - withUri($uri, $preserveHost): when $preserveHost is false OR no Host
     *   header exists yet, the Host header is re-derived from the new URI;
     *   otherwise the existing Host header is kept untouched.
     */
    final class Request extends MessageBase implements RequestInterface
    {
        private ?string $requestTargetOverride;
        private string $method;
        private UriInterface $uri;

        /** @param array<string, string|string[]> $headers */
        public function __construct(string $method, UriInterface $uri, array $headers = [], ?StreamInterface $body = null, string $protocolVersion = '1.1', string $requestTarget = '')
        {
            parent::__construct($body, $headers, $protocolVersion);
            if ($method === '' || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $method) !== 1) {
                throw new \InvalidArgumentException('Invalid HTTP method.');
            }
            if (!$this->hasHeader('Host') && $uri->getHost() !== '') {
                $host = $uri->getHost();
                if ($uri->getPort() !== null) {
                    $host .= ':' . $uri->getPort();
                } $this->addInitialHeader('Host', $host);
            }
            $this->method = $method;
            $this->uri = $uri;
            $this->requestTargetOverride = $requestTarget !== '' ? $requestTarget : null;
        }
        private function deriveRequestTarget(UriInterface $uri): string
        {
            $path = $uri->getPath();
            $target = $path === '' ? '/' : ($path[0] === '/' ? $path : '/' . $path);
            if ($uri->getQuery() !== '') {
                $target .= '?' . $uri->getQuery();
            }return $target;
        }
        #[\Override]
        public function getRequestTarget(): string
        {
            return $this->requestTargetOverride ?? $this->deriveRequestTarget($this->uri);
        }
        #[\Override]
        public function withRequestTarget(string $requestTarget): RequestInterface
        {
            if (preg_match('/[\x00-\x1F\x7F]/', $requestTarget) === 1) {
                throw new \InvalidArgumentException('Invalid request target.');
            }$n = clone $this;
            $n->requestTargetOverride = $requestTarget;
            return $n;
        }
        #[\Override]
        public function getMethod(): string
        {
            return $this->method;
        }
        #[\Override]
        public function withMethod(string $method): RequestInterface
        {
            \Zef\Framework\Validation\HttpMethodValidator::assert($method);
            $n = clone $this;
            $n->method = $method;
            return $n;
        }
        #[\Override]
        public function getUri(): UriInterface
        {
            return $this->uri;
        }
        #[\Override]
        public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
        {
            $n = clone $this;
            $n->uri = $uri;
            $existingHost = $this->getHeaderLine('Host');
            if (!$preserveHost || $existingHost === '') {
                if ($uri->getHost() !== '') {
                    $host = $uri->getHost();
                    if ($uri->getPort() !== null) {
                        $host .= ':' . $uri->getPort();
                    }$n = $n->withHeader('Host', $host);
                }
            }return $n;
        }
    }

}
