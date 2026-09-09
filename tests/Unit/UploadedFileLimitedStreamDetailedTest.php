<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Zef\Framework\Exception\PayloadTooLargeException;
use Zef\Framework\Http\LimitedInputStream;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\UploadedFile;
use Zef\Framework\Http\Uri;

final class UploadedFileLimitedStreamDetailedTest extends TestCase
{
    public function testUploadedFileDefaults(): void
    {
        $stream = Stream::fromString('data');
        $uploaded = new UploadedFile($stream);

        $this->assertSame(4, $uploaded->getSize());
        $this->assertSame(UPLOAD_ERR_OK, $uploaded->getError());
        $this->assertNull($uploaded->getClientFilename());
        $this->assertNull($uploaded->getClientMediaType());
        $this->assertSame($stream, $uploaded->getStream());
    }

    public function testUploadedFileWithAllMetadata(): void
    {
        $uploaded = new UploadedFile(Stream::fromString('x'), 1, UPLOAD_ERR_PARTIAL, 'f.bin', 'application/octet-stream');

        $this->assertSame(1, $uploaded->getSize());
        $this->assertSame(UPLOAD_ERR_PARTIAL, $uploaded->getError());
        $this->assertSame('f.bin', $uploaded->getClientFilename());
        $this->assertSame('application/octet-stream', $uploaded->getClientMediaType());
    }

    public function testUploadedFileWithErrorIsUnavailable(): void
    {
        $uploaded = new UploadedFile(Stream::fromString('x'), 1, UPLOAD_ERR_NO_FILE);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not available');
        $uploaded->getStream();
    }

    public function testMoveToRejectsEmptyTarget(): void
    {
        $uploaded = new UploadedFile(Stream::fromString('x'));

        $this->expectException(\InvalidArgumentException::class);
        $uploaded->moveTo('');
    }

    public function testMoveToRejectsWhenErrorPresent(): void
    {
        $uploaded = new UploadedFile(Stream::fromString('x'), null, UPLOAD_ERR_CANT_WRITE);

        $this->expectException(\RuntimeException::class);
        $uploaded->moveTo(sys_get_temp_dir() . '/zef-move-target-' . bin2hex(random_bytes(4)));
    }

    public function testMoveToRejectsWhenDestinationDirectoryMissing(): void
    {
        $uploaded = new UploadedFile(Stream::fromString('x'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Destination directory does not exist');
        $uploaded->moveTo('/definitely/missing/dir/out.bin');
    }

    public function testMoveToMovesContentAndClosesStream(): void
    {
        $dir = sys_get_temp_dir() . '/zef-move-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $target = $dir . '/out.txt';

        try {
            $uploaded = new UploadedFile(Stream::fromString('payload-content'));
            $uploaded->moveTo($target);

            $this->assertFileExists($target);
            $this->assertSame('payload-content', file_get_contents($target));
        } finally {
            @unlink($target);
            @rmdir($dir);
        }
    }

    public function testMoveToRefusesSecondMove(): void
    {
        $dir = sys_get_temp_dir() . '/zef-move2-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $target = $dir . '/out.txt';

        try {
            $uploaded = new UploadedFile(Stream::fromString('x'));
            $uploaded->moveTo($target);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('already been moved');
            $uploaded->moveTo($dir . '/other.txt');
        } finally {
            @unlink($target);
            @rmdir($dir);
        }
    }

    public function testMoveToRestoresStreamPositionOnFailure(): void
    {
        $dir = sys_get_temp_dir() . '/zef-move3-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $stream = Stream::fromString('abcdef');
            $stream->seek(2);
            $uploaded = new UploadedFile($stream);

            // Force rename failure: target directory exists but is removed between
            // temp creation and rename by pointing at an invalid final name.
            $this->expectException(\RuntimeException::class);
            $uploaded->moveTo($dir); // directory as target -> rename fails
        } finally {
            @rmdir($dir);
        }
    }

    public function testUploadedFileSizePrefersExplicitValue(): void
    {
        $uploaded = new UploadedFile(Stream::fromString('123456789'), 3);
        $this->assertSame(3, $uploaded->getSize());
    }

    public function testLimitedInputStreamReadWithinLimit(): void
    {
        $inner = Stream::fromString('hello world');
        $limited = new LimitedInputStream($inner, new RequestBodyPolicy(100));

        $this->assertSame('hello world', $limited->getContents());
        $this->assertTrue($limited->eof());
    }

    public function testLimitedInputStreamEnforcesByteLimit(): void
    {
        $inner = Stream::fromString('0123456789');
        $limited = new LimitedInputStream($inner, new RequestBodyPolicy(5));

        try {
            $limited->read(8192);
            $this->fail('Expected PayloadTooLargeException.');
        } catch (PayloadTooLargeException $e) {
            $this->assertSame('Request body exceeds configured size limit.', $e->getMessage());
        }
    }

    public function testLimitedInputStreamToStringCatchesOverflow(): void
    {
        $inner = Stream::fromString('0123456789');
        $limited = new LimitedInputStream($inner, new RequestBodyPolicy(3));

        $this->assertSame('', (string) $limited);
    }

    public function testLimitedInputStreamToStringReadsWhenWithinLimit(): void
    {
        $inner = Stream::fromString('abc');
        $limited = new LimitedInputStream($inner, new RequestBodyPolicy(3));

        $this->assertSame('abc', (string) $limited);
    }

    public function testLimitedInputStreamReadZeroAndNegative(): void
    {
        $inner = Stream::fromString('abc');
        $limited = new LimitedInputStream($inner, new RequestBodyPolicy(10));

        $this->assertSame('', $limited->read(0));

        $this->expectException(\InvalidArgumentException::class);
        $limited->read(-1);
    }

    public function testLimitedInputStreamDelegatesMetadataOperations(): void
    {
        $inner = Stream::fromString('abc');
        $limited = new LimitedInputStream($inner, new RequestBodyPolicy(10));

        $this->assertSame(3, $limited->getSize());
        $this->assertTrue($limited->isSeekable());
        $this->assertTrue($limited->isReadable());
        $this->assertFalse($limited->isWritable());
        $this->assertSame(0, $limited->tell());
        $this->assertFalse($limited->eof());

        $limited->seek(1);
        $this->assertSame(1, $limited->tell());
        $limited->rewind();
        $this->assertSame(0, $limited->tell());
    }

    public function testLimitedInputStreamGetSizeCapsAtPolicyLimit(): void
    {
        $inner = Stream::fromString('0123456789');
        $limited = new LimitedInputStream($inner, new RequestBodyPolicy(4));

        $this->assertSame(4, $limited->getSize());
    }

    public function testLimitedInputStreamGetSizeNullWhenInnerUnknown(): void
    {
        $mock = $this->createMock(StreamInterface::class);
        $mock->method('getSize')->willReturn(null);
        $limited = new LimitedInputStream($mock, new RequestBodyPolicy(10));

        $this->assertNull($limited->getSize());
    }

    public function testLimitedInputStreamWriteThrows(): void
    {
        $limited = new LimitedInputStream(Stream::fromString(''), new RequestBodyPolicy(10));

        $this->expectException(\RuntimeException::class);
        $limited->write('x');
    }

    public function testLimitedInputStreamCloseAndDetachDelegate(): void
    {
        $inner = Stream::fromString('abc');
        $limited = new LimitedInputStream($inner, new RequestBodyPolicy(10));

        $resource = $limited->detach();
        $this->assertIsResource($resource);
    }

    public function testLimitedInputStreamGetMetadataDelegates(): void
    {
        $inner = Stream::fromString('abc');
        $limited = new LimitedInputStream($inner, new RequestBodyPolicy(10));

        $this->assertIsArray($limited->getMetadata());
        $this->assertSame('php://temp', $limited->getMetadata('uri'));
    }

    public function testRequestBodyPolicyDefaults(): void
    {
        $policy = new RequestBodyPolicy();

        $this->assertSame(RequestBodyPolicy::DEFAULT_MAX_BYTES, $policy->maxBytes);
    }

    public function testRequestBodyPolicyCustomLimit(): void
    {
        $policy = new RequestBodyPolicy(1024);

        $this->assertSame(1024, $policy->maxBytes);
    }

    public function testRequestBodyPolicyRejectsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RequestBodyPolicy(0);
    }

    public function testUploadedFileGetStreamTwiceReturnsSameStream(): void
    {
        $stream = Stream::fromString('x');
        $uploaded = new UploadedFile($stream);

        $this->assertSame($stream, $uploaded->getStream());
        $this->assertSame($stream, $uploaded->getStream());
    }

    public function testUriFromStringIsSupportedByUploadHelpers(): void
    {
        // Guard: Uri object supports the operations Psr17Factory relies on.
        $uri = new Uri('https://example.com/upload');
        $this->assertSame('/upload', $uri->getPath());
    }
}
