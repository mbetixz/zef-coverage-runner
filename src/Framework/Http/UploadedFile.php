<?php

declare(strict_types=1);

namespace Zef\Framework\Http {
    use Psr\Http\Message\StreamInterface;
    use Psr\Http\Message\UploadedFileInterface;

    /**
     * PSR-7 uploaded file backed by a StreamInterface.
     *
     * Upload semantics:
     *  - $error is one of the UPLOAD_ERR_* constants; values other than
     *    UPLOAD_ERR_OK make the file unavailable (getStream() throws).
     *  - moveTo() is atomic (write-to-temp + rename), refuses to run after a
     *    successful move or when the destination directory does not exist.
     */
    final class UploadedFile implements UploadedFileInterface
    {
        private bool $moved = false;
        public function __construct(private StreamInterface $stream, private ?int $size = null, private int $error = UPLOAD_ERR_OK, private ?string $clientFilename = null, private ?string $clientMediaType = null)
        {
        }
        #[\Override]
        public function getStream(): StreamInterface
        {
            if ($this->error !== UPLOAD_ERR_OK) {
                throw new \RuntimeException("Uploaded file is not available (error {$this->error}).");
            }if ($this->moved) {
                throw new \RuntimeException('Uploaded file has already been moved.');
            }return $this->stream;
        }
        #[\Override]
        public function moveTo(string $targetPath): void
        {
            if ($targetPath === '') {
                throw new \InvalidArgumentException('Target path must not be empty.');
            }
            if ($this->error !== UPLOAD_ERR_OK) {
                throw new \RuntimeException("Cannot move uploaded file with error code {$this->error}.");
            }
            if ($this->moved) {
                throw new \RuntimeException('Uploaded file has already been moved.');
            }
            $directory = dirname($targetPath);
            if (!is_dir($directory)) {
                throw new \RuntimeException("Destination directory does not exist: '{$directory}'.");
            }
            $tmpTarget = $targetPath . '.zef-tmp-' . bin2hex(random_bytes(8));
            $dest = @fopen($tmpTarget, 'x+b');
            if ($dest === false) {
                throw new \RuntimeException('Unable to create temporary upload target.');
            }
            $success = false;
            $originalPosition = null;
            try {
                if ($this->stream->isSeekable()) {
                    $originalPosition = $this->stream->tell();
                    $this->stream->rewind();
                }
                while (!$this->stream->eof()) {
                    $chunk = $this->stream->read(8192);
                    if ($chunk === '') {
                        break;
                    }
                    $offset = 0;
                    $length = strlen($chunk);
                    while ($offset < $length) {
                        $written = fwrite($dest, substr($chunk, $offset));
                        if ($written === false || $written === 0) {
                            throw new \RuntimeException('Unable to write uploaded file.');
                        }
                        $offset += $written;
                    }
                }
                // fflush() is always available in PHP >= 8.0 (always-true guard removed).
                @fflush($dest);
                @fclose($dest);
                $dest = null;
                if (!@rename($tmpTarget, $targetPath)) {
                    throw new \RuntimeException("Unable to finalize uploaded file to '{$targetPath}'.");
                }
                $success = true;
            } finally {
                // $dest is either a resource needing close (exception path) or null (success path).
                if (is_resource($dest)) {
                    @fclose($dest);
                }
                if (!$success) {
                    @unlink($tmpTarget);
                }
                if (!$success && $originalPosition !== null) {
                    try {
                        $this->stream->seek($originalPosition);
                    } catch (\Throwable) {
                    }
                }
            }
            // Success path: rename() succeeded above (failures throw), so finalize state.
            $this->moved = true;
            $this->stream->close();
        }
        #[\Override]
        public function getSize(): ?int
        {
            return $this->size ?? $this->stream->getSize();
        }
        #[\Override]
        public function getError(): int
        {
            return $this->error;
        }
        #[\Override]
        public function getClientFilename(): ?string
        {
            return $this->clientFilename;
        }
        #[\Override]
        public function getClientMediaType(): ?string
        {
            return $this->clientMediaType;
        }
    }
}
