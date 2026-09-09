<?php

declare(strict_types=1);

namespace Zef\Framework\Http {
    use Psr\Http\Message\UriInterface;
    use Zef\Framework\Validation\PortRangeValidator;
    use Zef\Framework\Validation\TrustedHostValidator;

    final class Uri implements UriInterface
    {
        private string $scheme = '';
        private string $userInfo = '';
        private string $host = '';
        private ?int $port = null;
        private string $path = '';
        private string $query = '';
        private string $fragment = '';

        /**
         * @param list<string> $trustedHosts
         */
        public function __construct(string $uri = '', private readonly array $trustedHosts = [])
        {
            if ($uri === '') {
                return;
            }
            $this->assertNoControls($uri, 'URI');
            $parts = parse_url($uri);
            if ($parts === false) {
                throw new \InvalidArgumentException("Unable to parse URI '{$uri}'.");
            }
            $this->scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($this->scheme !== '' && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*\\z/', $this->scheme) !== 1) {
                throw new \InvalidArgumentException('Invalid URI scheme.');
            }
            $user = isset($parts['user']) ? (string) $parts['user'] : '';
            $pass = array_key_exists('pass', $parts) ? (string) $parts['pass'] : null;
            $this->userInfo = $this->encodeComponent($user, "!$&'()*+,;=:");
            if ($pass !== null) {
                $this->userInfo .= ':' . $this->encodeComponent($pass, "!$&'()*+,;=:");
            }
            $this->host = strtolower((string) ($parts['host'] ?? ''));
            $this->assertHost($this->host);
            $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
            new PortRangeValidator()->assert($this->port);
            $this->path = $this->encodeComponent((string) ($parts['path'] ?? ''), ":/@!$&'()*+,;=-._~");
            $this->query = $this->encodeComponent((string) ($parts['query'] ?? ''), ":/?@!$&'()*+,;=-._~");
            $this->fragment = $this->encodeComponent((string) ($parts['fragment'] ?? ''), ":/?@!$&'()*+,;=-._~");
            new TrustedHostValidator($this->trustedHosts)->assert($this->host);
        }

        private function assertNoControls(string $value, string $label): void
        {
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException("Invalid {$label} control characters.");
            }
        }

        private function encodeComponent(string $value, string $allowed): string
        {
            $result = '';
            $len = strlen($value);
            for ($i = 0;$i < $len;$i++) {
                $ch = $value[$i];
                $o = ord($ch);
                if ($ch === '%' && $i + 2 < $len && ctype_xdigit($value[$i + 1]) && ctype_xdigit($value[$i + 2])) {
                    $result .= '%' . strtoupper($value[$i + 1] . $value[$i + 2]);
                    $i += 2;
                    continue;
                }
                if (($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122) || ($o >= 48 && $o <= 57) || str_contains('-._~' . $allowed, $ch)) {
                    $result .= $ch;
                    continue;
                }
                $result .= sprintf('%%%02X', $o);
            }
            return $result;
        }

        private function assertHost(string $host): void
        {
            if ($host === '') {
                return;
            }
            $this->assertNoControls($host, 'URI host');
            if (str_contains($host, ':')) {
                if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                    throw new \InvalidArgumentException('Invalid URI host.');
                }
                return;
            }
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return;
            }
            if (strlen($host) > 253 || preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/', $host) !== 1) {
                throw new \InvalidArgumentException('Invalid URI host.');
            }
        }

        #[\Override]
        public function getScheme(): string
        {
            return $this->scheme;
        }
        #[\Override]
        public function getAuthority(): string
        {
            if ($this->host === '') {
                return '';
            }
            $displayHost = str_contains($this->host, ':') && !str_starts_with($this->host, '[') ? '[' . $this->host . ']' : $this->host;
            $authority = ($this->userInfo !== '' ? $this->userInfo . '@' : '') . $displayHost;
            if ($this->port !== null) {
                $authority .= ':' . $this->port;
            }
            return $authority;
        }
        #[\Override]
        public function getUserInfo(): string
        {
            return $this->userInfo;
        }
        #[\Override]
        public function getHost(): string
        {
            return $this->host;
        }
        #[\Override]
        public function getPort(): ?int
        {
            return $this->port;
        }
        #[\Override]
        public function getPath(): string
        {
            return $this->path;
        }
        #[\Override]
        public function getQuery(): string
        {
            return $this->query;
        }
        #[\Override]
        public function getFragment(): string
        {
            return $this->fragment;
        }

        #[\Override]
        public function withScheme(string $scheme): UriInterface
        {
            $this->assertNoControls($scheme, 'URI scheme');
            if ($scheme !== '' && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*\\z/', $scheme) !== 1) {
                throw new \InvalidArgumentException('Invalid URI scheme.');
            }
            $n = clone $this;
            $n->scheme = strtolower($scheme);
            return $n;
        }
        #[\Override]
        public function withUserInfo(string $user, ?string $password = null): UriInterface
        {
            $this->assertNoControls($user, 'URI user info');
            if ($password !== null) {
                $this->assertNoControls($password, 'URI user info');
            } $n = clone $this;
            $n->userInfo = $this->encodeComponent($user, "!$&'()*+,;=:") . ($password !== null ? ':' . $this->encodeComponent($password, "!$&'()*+,;=") : '');
            return $n;
        }
        #[\Override]
        public function withHost(string $host): UriInterface
        {
            $host = trim($host);
            if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
                $host = substr($host, 1, -1);
            } $this->assertHost($host);
            $n = clone $this;
            $n->host = strtolower($host);
            new TrustedHostValidator($this->trustedHosts)->assert($n->host);
            return $n;
        }
        #[\Override]
        public function withPort(?int $port): UriInterface
        {
            new PortRangeValidator()->assert($port);
            $n = clone $this;
            $n->port = $port;
            return $n;
        }
        #[\Override]
        public function withPath(string $path): UriInterface
        {
            $this->assertNoControls($path, 'URI path');
            $n = clone $this;
            $n->path = $this->encodeComponent($path, ":/@!$&'()*+,;=-._~");
            return $n;
        }
        #[\Override]
        public function withQuery(string $query): UriInterface
        {
            $this->assertNoControls($query, 'URI query');
            $n = clone $this;
            $n->query = $this->encodeComponent($query, ":/?@!$&'()*+,;=-._~");
            return $n;
        }
        #[\Override]
        public function withFragment(string $fragment): UriInterface
        {
            $this->assertNoControls($fragment, 'URI fragment');
            $n = clone $this;
            $n->fragment = $this->encodeComponent($fragment, ":/?@!$&'()*+,;=-._~");
            return $n;
        }
        #[\Override]
        public function __toString(): string
        {
            $uri = $this->scheme !== '' ? $this->scheme . ':' : '';
            if ($this->getAuthority() !== '') {
                $uri .= '//' . $this->getAuthority();
            } $uri .= $this->path;
            if ($this->query !== '') {
                $uri .= '?' . $this->query;
            } if ($this->fragment !== '') {
                $uri .= '#' . $this->fragment;
            } return $uri;
        }
    }

}
