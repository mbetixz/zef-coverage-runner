<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Http\Stream;

/**
 * Batch 19 coverage: Stream.php I/O failure guards. A user-space stream
 * wrapper registered under 'zef19://' lets the underlying primitives
 * (fread / fwrite / fstat / stream_read) be forced to fail deterministically
 * — something no real php:// stream can do in a unit run. Native E_WARNINGs
 * raised by the failing primitives are swallowed through a temporary error
 * handler (the suite runs with failOnWarning=true) so the Stream
 * RuntimeException branches are reached.
 *
 * Infeasible lines (documented, not skippable from a unit test):
 *  - Stream.php:26  fopen('php://temp') failing — memory/wrapper-availability
 *                   guard; cannot be forced without destabilizing the suite.
 *  - Stream.php:94  ftell() returning false — PHP tracks the position of
 *                   user-space streams internally and never consults
 *                   stream_tell(), so no wrapper can make ftell() fail.
 *  - Stream.php:185 stream_get_contents() returning false — a user-space
 *                   stream_read() === false is treated as EOF by PHP (returns
 *                   ''), not as an I/O error.
 */
final class Batch19StreamEdgeTest extends TestCase
{
    private static bool $registered = false;

    #[Override]
    protected function setUp(): void
    {
        if (!self::$registered) {
            stream_wrapper_register('zef19', FailStreamWrapper::class);
            self::$registered = true;
        }
        FailStreamWrapper::$mode = 'ok';
    }

    #[Override]
    protected function tearDown(): void
    {
        FailStreamWrapper::$mode = 'ok';
    }

    /** @param 'rb'|'wb' $fopenMode */
    private function openFail(string $fopenMode, string $behavior): Stream
    {
        FailStreamWrapper::$mode = 'ok';
        $resource = fopen('zef19://x', $fopenMode);
        if ($resource === false) {
            self::fail('failed to open zef19 stream wrapper');
        }
        FailStreamWrapper::$mode = $behavior;

        return new Stream($resource);
    }

    /** Run $fn with native warnings suppressed so the caller's own error path runs. */
    private function swallowing(callable $fn): mixed
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            return true;
        });
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    public function testToStringSwallowsReadException(): void
    {
        // stream_read() raises inside __toString -> catch (\Throwable)
        // returns '' instead of propagating.
        $stream = $this->openFail('rb', 'read-throw');

        self::assertSame('', (string) $stream);
    }

    public function testGetSizeReturnsNullWhenStatFails(): void
    {
        // fstat() reports failure (stream_stat() === false) -> the non-array
        // stats guard returns null.
        $stream = $this->openFail('rb', 'stat-false');

        self::assertNull($this->swallowing(static fn (): ?int => $stream->getSize()));
    }

    public function testWriteThrowsWhenFwriteFails(): void
    {
        // fwrite() reports failure (stream_write() === false).
        $stream = $this->openFail('wb', 'write-false');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to write to stream.');
        $this->swallowing(static fn (): int => $stream->write('data'));
    }

    public function testReadThrowsWhenFreadFails(): void
    {
        // fread() reports failure (stream_read() === false).
        $stream = $this->openFail('rb', 'read-false');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read stream.');
        $this->swallowing(static fn (): string => $stream->read(1));
    }
}

/**
 * Minimal user-space stream wrapper whose primitive results are controlled
 * per test through the static $mode switch. Registered only inside
 * Batch19StreamEdgeTest under the 'zef19://' scheme.
 */
final class FailStreamWrapper
{
    public static string $mode = 'ok';

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    /** @return string|false */
    public function stream_read(int $count): string|false
    {
        if (self::$mode === 'read-throw') {
            throw new \RuntimeException('simulated read failure');
        }

        return self::$mode === 'read-false' ? false : '';
    }

    public function stream_eof(): bool
    {
        return self::$mode !== 'read-false';
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return true;
    }

    /** @return int|false */
    public function stream_write(string $data): int|false
    {
        return self::$mode === 'write-false' ? false : strlen($data);
    }

    /** @return int|false */
    public function stream_tell(): int|false
    {
        return self::$mode === 'tell-false' ? false : 0;
    }

    /** @return array<string, mixed>|false */
    public function stream_stat(): array|false
    {
        return self::$mode === 'stat-false' ? false : [];
    }
}
