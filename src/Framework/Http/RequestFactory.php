<?php

declare(strict_types=1);

namespace Zef\Framework\Http {

    use Psr\Http\Message\ServerRequestInterface;

    final class RequestFactory
    {
        /**
         * Builds a PSR-7 server request from PHP superglobals.
         *
         * @param list<string> $trustedHosts
         * @param list<string> $trustedProxies
         *
         * @throws \InvalidArgumentException            When REQUEST_URI, Host/authority or
         *                                              forwarded protocol is malformed.
         * @throws \Zef\Framework\Exception\PayloadTooLargeException When the request body exceeds
         *                                              the configured limit.
         */
        public static function fromGlobals(array $trustedHosts = [], array $trustedProxies = [], ?RequestBodyPolicy $bodyPolicy = null): ServerRequestInterface
        {
            /** @var array<string, mixed> $server */
            $server = $_SERVER;
            $method = strtoupper(self::serverStr($server, 'REQUEST_METHOD', 'GET'));
            $protocol = self::protocolVersion(self::serverStr($server, 'SERVER_PROTOCOL', 'HTTP/1.1'));
            $headers = self::extractHeaders($server);
            $uri = self::buildUri($server, $trustedHosts, $trustedProxies);
            /** @var array<string, string> $cookies */
            $cookies = $_COOKIE;
            /** @var array<string, mixed> $query */
            $query = $_GET;
            /** @var array<string, mixed> $uploadsRaw */
            $uploadsRaw = $_FILES;
            $uploads = self::normalizeUploads($uploadsRaw);
            $bodyPolicy ??= new RequestBodyPolicy();
            $contentLengthHeader = (string) ($headers['content-length'][0] ?? '');
            if ($contentLengthHeader !== '' && ctype_digit($contentLengthHeader) && (int) $contentLengthHeader > $bodyPolicy->maxBytes) {
                throw new \Zef\Framework\Exception\PayloadTooLargeException('Request body exceeds configured size limit.');
            }
            $input = fopen('php://input', 'rb');
            if ($input === false) {
                throw new \RuntimeException('Unable to open request input stream.');
            } $body = new LimitedInputStream(new Stream($input), $bodyPolicy);
            $contentType = strtolower(trim(explode(';', (string) ($headers['content-type'][0] ?? ''))[0]));
            $parsedBody = null;
            if ($contentType === 'application/x-www-form-urlencoded' || $contentType === 'multipart/form-data') {
                $parsedBody = $_POST !== [] ? $_POST : null;
            }
            return new ServerRequest($method, $uri, $server, $cookies, $query, $uploads, $parsedBody, $headers, $body, $protocol, self::requestTarget($server, $uri));
        }

        public static function decodeJsonBody(ServerRequestInterface $request, bool $associative = true): mixed
        {
            $body = $request->getBody();
            $position = null;
            if ($body->isSeekable()) {
                $position = $body->tell();
                $body->rewind();
            }
            $raw = $body->getContents();
            if ($position !== null) {
                $body->seek($position);
            }
            if ($raw === '') {
                return null;
            }
            try {
                $decoded = json_decode($raw, $associative, 512, JSON_THROW_ON_ERROR);
                return $decoded;
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('Malformed JSON request body.', 0, $e);
            }
        }

        /**
         * @param array<string, mixed> $server          $_SERVER-style values (non-scalar ignored).
         * @param list<string>          $trustedHosts
         * @param list<string>          $trustedProxies
         *
         * @throws \InvalidArgumentException When REQUEST_URI, authority/Host or forwarded
         *                                   protocol is malformed.
         */
        private static function buildUri(array $server, array $trustedHosts, array $trustedProxies): Uri
        {
            $requestUri = self::serverStr($server, 'REQUEST_URI', '/');
            $parts = parse_url($requestUri);
            if ($parts === false) {
                throw new \InvalidArgumentException('Malformed REQUEST_URI.');
            }
            $path = (string) ($parts['path'] ?? '/');
            $query = (string) ($parts['query'] ?? '');

            $remote = self::serverStr($server, 'REMOTE_ADDR', '');
            $trusted = self::isTrustedProxy($remote, $trustedProxies);
            $hostHeader = $trusted && isset($server['HTTP_X_FORWARDED_HOST'])
                ? self::firstForwardedValue(self::serverStr($server, 'HTTP_X_FORWARDED_HOST', ''))
                : self::serverStr($server, 'HTTP_HOST', self::serverStr($server, 'SERVER_NAME', ''));
            [$host, $forwardedPort] = self::parseAuthority($hostHeader);

            $scheme = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off' ? 'https' : 'http';
            if ($trusted && isset($server['HTTP_X_FORWARDED_PROTO'])) {
                $forwardedScheme = strtolower(trim(explode(',', self::serverStr($server, 'HTTP_X_FORWARDED_PROTO', ''))[0]));
                if (!in_array($forwardedScheme, ['http', 'https'], true)) {
                    throw new \InvalidArgumentException('Invalid forwarded protocol.');
                }
                $scheme = $forwardedScheme;
            }
            $port = $forwardedPort;
            if ($port === null && isset($server['SERVER_PORT'])) {
                $p = self::serverInt($server, 'SERVER_PORT', 0);
                if (($scheme === 'http' && $p !== 80) || ($scheme === 'https' && $p !== 443)) {
                    $port = $p;
                }
            }

            $script = self::serverStr($server, 'SCRIPT_NAME', '');
            $scriptFilename = self::serverStr($server, 'SCRIPT_FILENAME', '');
            $scriptPath = $script !== '' ? (parse_url($script, PHP_URL_PATH) ?: '') : '';
            $scriptBase = $scriptPath !== '' ? basename($scriptPath) : '';
            $filenameBase = $scriptFilename !== '' ? basename($scriptFilename) : '';
            $isFrontControllerScript = $scriptPath !== '' && $filenameBase !== '' && $scriptBase === $filenameBase;
            if ($isFrontControllerScript) {
                if ($path === $scriptPath) {
                    $path = '/';
                } elseif (str_starts_with($path, $scriptPath . '/')) {
                    $path = substr($path, strlen($scriptPath));
                }
                if ($path === '') {
                    $path = '/';
                }
            }
            $base = $scheme . '://' . ($host !== '' ? $host : 'localhost') . ($port !== null ? ':' . $port : '') . $path . ($query !== '' ? '?' . $query : '');
            return new Uri($base, $trustedHosts);
        }
        /**
         * Normalizes $_SERVER-style HTTP_* keys into lowercase header names.
         *
         * @param array<string, mixed> $server
         * @return array<string, list<string>>  Lowercase header name => single-value list.
         *
         * @throws \Zef\Framework\Exception\InvalidHeaderException When a header name/value fails validation.
         */
        private static function extractHeaders(array $server): array
        {
            $validator = new \Zef\Framework\Validation\HeaderValidator();
            $headers = [];
            foreach ($server as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }$name = null;
                if (str_starts_with($key, 'HTTP_')) {
                    $name = str_replace('_', '-', substr($key, 5));
                } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                    $name = str_replace('_', '-', $key);
                }if ($name === null) {
                    continue;
                }$validator->assertName($name);
                $validator->assertValue($name, $value);
                $headers[strtolower($name)] = [$value];
            }
            return $headers;
        }
        /**
         * Normalizes a $_FILES-style tree into nested UploadedFileInterface
         * leaves. Supports both the flat PHP 8.x layout and the legacy nested
         * (PHP 5.x) per-field array layout; non-upload entries pass through.
         *
         * @param array<string, mixed> $files  Raw $_FILES tree.
         * @return array<string, mixed>        Tree of UploadedFileInterface leaves
         *                                      (non-upload entries unchanged).
         */
        private static function normalizeUploads(array $files): array
        {
            $factory = new Psr17Factory();
            /**
             * Recursively rebuilds one upload spec. Leaf level: scalar fields
             * (name/type/tmp_name/error/size); deeper level: same shape keyed
             * by the file-input index.
             *
             * @param mixed $name
             * @param mixed $type
             * @param mixed $tmp
             * @param mixed $error  int|array<int, mixed>
             * @param mixed $size
             *
             * @return mixed  UploadedFileInterface leaf or array of leaves.
             */
            $build = function (mixed $name, mixed $type, mixed $tmp, mixed $error, mixed $size) use (&$build, $factory): mixed {
                if (!is_array($error)) {
                    $error = self::toInt($error);
                    $tmp = self::toStr($tmp);
                    $stream = ($error === UPLOAD_ERR_OK && $tmp !== '') ? $factory->createStreamFromFile($tmp, 'rb') : $factory->createStream('');
                    $sizeValue = $size === null ? null : self::toInt($size);
                    return $factory->createUploadedFile($stream, $sizeValue, $error, is_string($name) ? $name : null, is_string($type) ? $type : null);
                }
                $result = [];
                foreach ($error as $key => $err) {
                    $result[$key] = $build(
                        is_array($name) ? ($name[$key] ?? null) : null,
                        is_array($type) ? ($type[$key] ?? null) : null,
                        is_array($tmp) ? ($tmp[$key] ?? null) : null,
                        $err,
                        is_array($size) ? ($size[$key] ?? null) : null,
                    );
                }
                return $result;
            };
            /** @var array<string, mixed> $out */
            $out = [];
            foreach ($files as $field => $spec) {
                if (!is_array($spec) || !array_key_exists('error', $spec)) {
                    $out[$field] = $spec;
                    continue;
                }
                $out[$field] = $build($spec['name'] ?? null, $spec['type'] ?? null, $spec['tmp_name'] ?? null, $spec['error'], $spec['size'] ?? null);
            }
            return $out;
        }

        private static function toInt(mixed $value): int
        {
            return is_numeric($value) ? (int) $value : 0;
        }

        private static function toStr(mixed $value): string
        {
            return is_scalar($value) ? (string) $value : '';
        }

        private static function protocolVersion(string $protocol): string
        {
            return preg_match('/^HTTP\/(\d+\.\d+)$/', $protocol, $m) === 1 ? $m[1] : '1.1';
        }
        /**
         * @param array<string, mixed> $server
         */
        private static function requestTarget(array $server, \Psr\Http\Message\UriInterface $uri): string
        {
            $target = self::serverStr($server, 'REQUEST_URI', $uri->getPath() ?: '/');
            if (preg_match('/[\r\n]/', $target) === 1) {
                throw new \InvalidArgumentException('Invalid request target.');
            }
            return $target;
        }

        /** @return array{0:string,1:int|null} */
        private static function parseAuthority(string $authority): array
        {
            $authority = trim($authority);
            if ($authority === '' || preg_match('~[\x00-\x20\x7f@\\/?#]~', $authority) === 1) {
                throw new \InvalidArgumentException('Malformed Host header.');
            }
            $host = $authority;
            $port = null;
            if ($authority[0] === '[') {
                $close = strpos($authority, ']');
                if ($close === false) {
                    throw new \InvalidArgumentException('Malformed Host header.');
                }
                $host = substr($authority, 1, $close - 1);
                $rest = substr($authority, $close + 1);
                if ($rest !== '') {
                    if (!str_starts_with($rest, ':') || !ctype_digit(substr($rest, 1))) {
                        throw new \InvalidArgumentException('Malformed Host header.');
                    }
                    $port = (int) substr($rest, 1);
                }
            } elseif (substr_count($authority, ':') === 1) {
                [$host, $portText] = explode(':', $authority, 2);
                if ($portText === '' || !ctype_digit($portText)) {
                    throw new \InvalidArgumentException('Malformed Host header.');
                }
                $port = (int) $portText;
            }
            $host = strtolower(trim($host));
            $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
            $isDns = self::isValidDnsHost($host);
            if ($host === '' || (!$isIp && !$isDns)) {
                throw new \InvalidArgumentException('Malformed Host header.');
            }
            if ($port !== null && ($port < 1 || $port > 65535)) {
                throw new \InvalidArgumentException('Malformed Host header.');
            }
            return [$host, $port];
        }

        /**
         * @param array<string, mixed> $server
         * Read a scalar $_SERVER-style value as string. Non-scalar/missing
         *  values fall back to $default, mirroring the previous (string) cast
         *  semantics for every value that can actually occur at runtime.
         */
        private static function serverStr(array $server, string $key, string $default = ''): string
        {
            $value = $server[$key] ?? $default;
            return is_scalar($value) ? (string) $value : $default;
        }

        /**
         * @param array<string, mixed> $server
         * Read a scalar $_SERVER-style value as int; non-numeric/missing
         *  values fall back to $default (same effective result as (int) cast).
         */
        private static function serverInt(array $server, string $key, int $default = 0): int
        {
            $value = $server[$key] ?? $default;
            return is_numeric($value) ? (int) $value : $default;
        }

        private static function isValidDnsHost(string $host): bool
        {
            if ($host === '' || strlen($host) > 253) {
                return false;
            }
            $host = rtrim($host, '.');
            if ($host === '') {
                return false;
            }
            foreach (explode('.', $host) as $label) {
                $length = strlen($label);
                if ($length < 1 || $length > 63 || $label[0] === '-' || $label[$length - 1] === '-') {
                    return false;
                }
                for ($i = 0; $i < $length; $i++) {
                    $char = $label[$i];
                    if (!(($char >= 'a' && $char <= 'z') || ($char >= '0' && $char <= '9') || $char === '-')) {
                        return false;
                    }
                }
            }
            return true;
        }

        private static function firstForwardedValue(string $value): string
        {
            return trim(explode(',', $value, 2)[0]);
        }

        /**
         * @param list<string> $trusted
         */
        private static function isTrustedProxy(string $remote, array $trusted): bool
        {
            $remote = trim($remote);
            if ($remote === '' || filter_var($remote, FILTER_VALIDATE_IP) === false) {
                return false;
            }
            foreach ($trusted as $entry) {
                $entry = trim((string) $entry);
                if ($entry === $remote) {
                    return true;
                }
                if (!str_contains($entry, '/')) {
                    continue;
                }
                [$network, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
                if ($network === null || $prefix === null || !ctype_digit($prefix)) {
                    continue;
                }
                if (self::ipInCidr($remote, $network, (int) $prefix)) {
                    return true;
                }
            }
            return false;
        }

        private static function ipInCidr(string $ip, string $network, int $prefix): bool
        {
            $ipBin = @inet_pton($ip);
            $networkBin = @inet_pton($network);
            if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
                return false;
            }
            $maxPrefix = strlen($ipBin) * 8;
            if ($prefix < 0 || $prefix > $maxPrefix) {
                return false;
            }
            $fullBytes = intdiv($prefix, 8);
            $remainingBits = $prefix % 8;
            if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) {
                return false;
            }
            if ($remainingBits === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            return (ord($ipBin[$fullBytes]) & $mask) === (ord($networkBin[$fullBytes]) & $mask);
        }
    }
}

namespace {
    require_once __DIR__ . '/RequestBodyPolicy.php';
    require_once __DIR__ . '/LimitedInputStream.php';
}
