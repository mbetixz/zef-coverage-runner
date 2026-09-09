<?php

declare(strict_types=1);

namespace Zef\Framework\Http {
    final class LimitedInputStream implements \Psr\Http\Message\StreamInterface
    {
        private int $observedBytes = 0;
        public function __construct(private readonly \Psr\Http\Message\StreamInterface $inner, private readonly RequestBodyPolicy $policy)
        {
        }
        #[\Override]
        public function __toString(): string
        {
            try {
                return $this->getContents();
            } catch (\Zef\Framework\Exception\PayloadTooLargeException) {
                return '';
            } catch (\Throwable) {
                return '';
            }
        }
        #[\Override]
        public function close(): void
        {
            $this->inner->close();
        }
        #[\Override]
        public function detach(): mixed
        {
            return $this->inner->detach();
        }
        #[\Override]
        public function getSize(): ?int
        {
            $size = $this->inner->getSize();
            return $size === null ? null : min($size, $this->policy->maxBytes);
        }
        #[\Override]
        public function tell(): int
        {
            return $this->inner->tell();
        }
        #[\Override]
        public function eof(): bool
        {
            return $this->inner->eof();
        }
        #[\Override]
        public function isSeekable(): bool
        {
            return $this->inner->isSeekable();
        }
        #[\Override]
        public function seek(int $offset, int $whence = SEEK_SET): void
        {
            $this->inner->seek($offset, $whence);
        }
        #[\Override]
        public function rewind(): void
        {
            $this->inner->rewind();
        }
        #[\Override]
        public function isWritable(): bool
        {
            return false;
        }
        #[\Override]
        public function write(string $string): int
        {
            throw new \RuntimeException('Limited input stream is read-only.');
        }
        #[\Override]
        public function isReadable(): bool
        {
            return $this->inner->isReadable();
        }
        #[\Override]
        public function read(int $length): string
        {
            if ($length < 0) {
                throw new \InvalidArgumentException('Length must be non-negative.');
            }
            if ($length === 0) {
                return '';
            }
            $remaining = $this->policy->maxBytes - $this->observedBytes;
            $probe = min($length, max(1, $remaining + 1));
            $data = $this->inner->read($probe);
            $len = strlen($data);
            if ($this->observedBytes + $len > $this->policy->maxBytes) {
                $this->observedBytes = $this->policy->maxBytes + 1;
                throw new \Zef\Framework\Exception\PayloadTooLargeException('Request body exceeds configured size limit.');
            }
            $this->observedBytes += $len;
            return $data;
        }
        #[\Override]
        public function getContents(): string
        {
            $out = '';
            while (!$this->eof()) {
                $chunk = $this->read(8192);
                if ($chunk === '') {
                    break;
                }
                $out .= $chunk;
            }
            return $out;
        }
        #[\Override]
        public function getMetadata(?string $key = null): mixed
        {
            return $this->inner->getMetadata($key);
        }
    }
}
