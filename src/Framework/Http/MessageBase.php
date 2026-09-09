<?php

declare(strict_types=1);

namespace Zef\Framework\Http {
    use Psr\Http\Message\MessageInterface;
    use Psr\Http\Message\StreamInterface;
    use Zef\Framework\Validation\HeaderValidator;

    /**
     * PSR-7 message base: shared header/body normalization for requests and
     * responses (RF-02.4 header normalization core).
     *
     * Header storage contract:
     * - Header NAMES are case-insensitive per PSR-7. `$headerNames` keeps a
     *   lowercase-name => stored-key map so lookups (hasHeader/getHeader/
     *   getHeaderLine) match regardless of case, while the original casing of
     *   the most recent write is preserved as the canonical storage key.
     * - Setting semantics follow PSR-7 exactly:
     *   - withHeader()      REPLACES any existing header with the same
     *                       case-insensitive name (old key unset first).
     *   - withAddedHeader() APPENDS values when the case-insensitive name
     *                       already exists, otherwise creates the header.
     *   - withoutHeader()   removes all values for the name, ignoring case.
     * - Every stored value is normalized through array_values(array_map(
     *   (string))) so the stored shape is always list<string> — callers
     *   receive list<string> from getHeader() and a single ', '-joined string
     *   from getHeaderLine().
     * - Validation is enforced BEFORE any mutation via HeaderValidator:
     *   names must be RFC 7230 tokens, and values MUST NOT contain CR/LF/NUL
     *   — so response splitting / header injection is prevented by
     *   construction (InvalidHeaderException).
     * - The constructor seeds headers through setHeader() (same validation +
     *   replace semantics as withHeader), so a header array passed at build
     *   time can never bypass validation.
     *
     * Body contract: bodyString() rewinds a seekable stream, reads all
     * contents, then restores the previous pointer; unseekable/unreadable
     * streams degrade to '' (never throw).
     */
    abstract class MessageBase implements MessageInterface
    {
        protected string $protocolVersion = '1.1';
        /** @var array<string,array<int,string>> */
        protected array $headers = [];
        /** @var array<string,string> lowercase header name => stored/original header key */
        private array $headerNames = [];
        protected StreamInterface $body;
        protected HeaderValidator $headerValidator;

        /** @param array<string,string|string[]> $headers */
        public function __construct(?StreamInterface $body = null, array $headers = [], string $protocolVersion = '1.1')
        {
            $this->body = $body ?? Stream::fromString('');
            $this->headerValidator = new HeaderValidator();
            if (preg_match('/^\d+\.\d+$/', $protocolVersion) !== 1) {
                throw new \InvalidArgumentException('Invalid HTTP protocol version.');
            }
            $this->protocolVersion = $protocolVersion;
            foreach ($headers as $name => $value) {
                $this->setHeader((string) $name, $value);
            }
        }

        #[\Override]
        public function getProtocolVersion(): string
        {
            return $this->protocolVersion;
        }
        #[\Override]
        public function withProtocolVersion(string $version): MessageInterface
        {
            if (preg_match('/^\d+\.\d+$/', $version) !== 1) {
                throw new \InvalidArgumentException('Invalid HTTP protocol version.');
            } $n = clone $this;
            $n->protocolVersion = $version;
            return $n;
        }
        #[\Override]
        /** @return array<string,array<int,string>> */
        public function getHeaders(): array
        {
            return $this->headers;
        }
        #[\Override]
        public function hasHeader(string $name): bool
        {
            return $this->findHeaderKey($name) !== null;
        }
        #[\Override]
        /** @return array<int,string> */
        public function getHeader(string $name): array
        {
            $key = $this->findHeaderKey($name);
            return $key === null ? [] : $this->headers[$key];
        }
        #[\Override]
        public function getHeaderLine(string $name): string
        {
            return implode(', ', $this->getHeader($name));
        }
        #[\Override]
        /** @param string|string[] $value */
        public function withHeader(string $name, $value): MessageInterface
        {
            $n = clone $this;
            $n->validateHeader($name, $value);
            $key = strtolower($name);
            $old = $n->headerNames[$key] ?? null;
            if ($old !== null) {
                unset($n->headers[$old]);
            }
            $n->headers[$name] = array_values(array_map(static fn ($v): string => (string) $v, is_array($value) ? $value : [$value]));
            $n->headerNames[$key] = $name;
            return $n;
        }

        #[\Override]
        /** @param string|string[] $value */
        public function withAddedHeader(string $name, $value): MessageInterface
        {
            $n = clone $this;
            $n->validateHeader($name, $value);
            $key = strtolower($name);
            $old = $n->headerNames[$key] ?? null;
            $values = array_values(array_map(static fn ($v): string => (string) $v, is_array($value) ? $value : [$value]));
            if ($old !== null) {
                $n->headers[$old] = array_merge($n->headers[$old], $values);
            } else {
                $n->headers[$name] = $values;
                $n->headerNames[$key] = $name;
            }
            return $n;
        }

        #[\Override]
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
        #[\Override]
        public function getBody(): StreamInterface
        {
            return $this->body;
        }
        #[\Override]
        public function withBody(StreamInterface $body): MessageInterface
        {
            $n = clone $this;
            $n->body = $body;
            return $n;
        }
        public function bodyString(): string
        {
            $pos = null;
            try {
                if ($this->body->isSeekable()) {
                    $pos = $this->body->tell();
                } $this->body->rewind();
                $s = $this->body->getContents();
                if ($pos !== null) {
                    $this->body->seek($pos);
                } return $s;
            } catch (\Throwable) {
                return '';
            }
        }
        /** @param string|string[] $value */
        protected function addInitialHeader(string $name, $value): void
        {
            $this->setHeader($name, $value);
        }
        /** @param string|string[] $value */
        private function setHeader(string $name, $value): void
        {
            $this->validateHeader($name, $value);
            $key = strtolower($name);
            $old = $this->headerNames[$key] ?? null;
            if ($old !== null) {
                unset($this->headers[$old]);
            }
            $this->headers[$name] = array_values(array_map(static fn ($v): string => (string) $v, is_array($value) ? $value : [$value]));
            $this->headerNames[$key] = $name;
        }

        private function findHeaderKey(string $name): ?string
        {
            return $this->headerNames[strtolower($name)] ?? null;
        }
        /** @param string|string[] $value */
        private function validateHeader(string $name, $value): void
        {
            $this->headerValidator->assertName($name);
            foreach (is_array($value) ? $value : [$value] as $v) {
                $this->headerValidator->assertValue($name, (string) $v);
            }
        }
    }

}
