<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\UploadedFile;

/**
 * Batch 8 coverage: Http\Stream + Http\UploadedFile error paths.
 *
 * Deterministic: all cases use in-memory php://temp streams or explicit
 * mocked StreamInterface resources; no real network/filesystem dependence
 * (the only temp file created is cleaned up in a finally block).
 */
final class Batch8HttpStreamUploadedFileErrorTest extends TestCase
{
    // ---------------------------------------------------------------- Stream

    public function testConstructRejectsNonResource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stream resource required.');
        // @phpstan-ignore argument.type (deliberate invalid input under test)
        new Stream('not-a-resource');
    }

    public function testReadRejectsNegativeLength(): void
    {
        $stream = Stream::fromString('abc');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Length must be non-negative.');
        $stream->read(-1);
    }

    public function testReadOnClosedStreamThrows(): void
    {
        $stream = Stream::fromString('abc');
        $stream->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is not readable.');
        $stream->read(1);
    }

    public function testReadZeroReturnsEmptyString(): void
    {
        $stream = Stream::fromString('abc');
        $this->assertSame('', $stream->read(0));
    }

    public function testWriteOnReadOnlyStreamThrows(): void
    {
        $resource = fopen('php://memory', 'rb');
        $this->assertIsResource($resource);
        $stream = new Stream($resource);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is not writable.');
        $stream->write('x');
    }

    public function testWriteAfterCloseThrows(): void
    {
        $stream = Stream::fromString('');
        $stream->close();
        $this->expectException(RuntimeException::class);
        $stream->write('x');
    }

    public function testTellAfterDetachThrows(): void
    {
        $stream = Stream::fromString('abc');
        $stream->detach();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream detached.');
        $stream->tell();
    }

    public function testSeekOnClosedStreamThrows(): void
    {
        $stream = Stream::fromString('abc');
        $stream->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is not seekable.');
        $stream->seek(5);
    }

    public function testEofOnDetachedStreamReturnsTrue(): void
    {
        $stream = Stream::fromString('abc');
        $stream->detach();
        $this->assertTrue($stream->eof());
    }

    public function testIsSeekableAfterCloseIsFalse(): void
    {
        $stream = Stream::fromString('abc');
        $stream->close();
        $this->assertFalse($stream->isSeekable());
    }

    public function testGetContentsAfterCloseThrows(): void
    {
        $stream = Stream::fromString('abc');
        $stream->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is not readable.');
        $stream->getContents();
    }

    public function testGetMetadataAfterCloseReturnsNullForScalarKey(): void
    {
        $stream = Stream::fromString('abc');
        $stream->close();
        $this->assertNull($stream->getMetadata('mode'));
        $this->assertSame([], $stream->getMetadata());
    }

    public function testDetachTwiceYieldsNull(): void
    {
        $stream = Stream::fromString('abc');
        $stream->detach();
        $this->assertNull($stream->detach());
    }

    public function testToStringOnReadOnlyStreamWithRewind(): void
    {
        $resource = fopen('php://memory', 'w+b');
        $this->assertIsResource($resource);
        fwrite($resource, 'payload');
        $stream = new Stream($resource);
        $this->assertSame('payload', (string) $stream);
    }

    public function testReadableWriteableFlagReflectsMode(): void
    {
        $stream = Stream::fromString('abc');
        $this->assertTrue($stream->isReadable());
        $this->assertTrue($stream->isWritable());

        $readOnly = fopen('php://memory', 'rb');
        $this->assertIsResource($readOnly);
        $ro = new Stream($readOnly);
        $this->assertTrue($ro->isReadable());
        $this->assertFalse($ro->isWritable());
    }

    // ------------------------------------------------------------ UploadedFile

    public function testGetStreamThrowsWhenUploadError(): void
    {
        $upload = new UploadedFile(Stream::fromString(''), 0, UPLOAD_ERR_NO_FILE);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not available');
        $upload->getStream();
    }

    public function testMoveToEmptyTargetRejected(): void
    {
        $upload = new UploadedFile(Stream::fromString('x'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');
        $upload->moveTo('');
    }

    public function testMoveToWithUploadErrorThrows(): void
    {
        $upload = new UploadedFile(Stream::fromString(''), 0, UPLOAD_ERR_INI_SIZE);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error code');
        $upload->moveTo(sys_get_temp_dir() . '/zef-b8-move-err.bin');
    }

    public function testMoveToNonexistentDirectoryThrows(): void
    {
        $upload = new UploadedFile(Stream::fromString('x'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');
        $upload->moveTo('/no/such/dir/target.bin');
    }

    public function testGetStreamAfterMoveThrows(): void
    {
        $target = tempnam(sys_get_temp_dir(), 'zef-b8-moved-');
        $this->assertNotFalse($target);
        try {
            $upload = new UploadedFile(Stream::fromString('content'));
            $upload->moveTo($target);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('already been moved');
            $upload->getStream();
        } finally {
            @unlink($target);
        }
    }

    public function testGetSizePrefersExplicitValue(): void
    {
        $upload = new UploadedFile(Stream::fromString('1234567890'), 3);
        $this->assertSame(3, $upload->getSize());
    }

    public function testGetSizeFallsBackToStreamSize(): void
    {
        $upload = new UploadedFile(Stream::fromString('1234567890'));
        $this->assertSame(10, $upload->getSize());
    }

    public function testClientMetadataAccessors(): void
    {
        $upload = new UploadedFile(
            Stream::fromString('x'),
            1,
            UPLOAD_ERR_OK,
            'photo.jpg',
            'image/jpeg',
        );
        $this->assertSame('photo.jpg', $upload->getClientFilename());
        $this->assertSame('image/jpeg', $upload->getClientMediaType());
        $this->assertSame(UPLOAD_ERR_OK, $upload->getError());
    }

    public function testMoveToNonSeekableStreamStillCopies(): void
    {
        $inner = $this->createMock(StreamInterface::class);
        $inner->method('isSeekable')->willReturn(false);
        $inner->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $inner->method('read')->willReturn('chunk');
        $inner->expects($this->once())->method('close');
        $inner->expects($this->never())->method('rewind');

        $target = tempnam(sys_get_temp_dir(), 'zef-b8-noseek-');
        $this->assertNotFalse($target);
        try {
            $upload = new UploadedFile($inner);
            $upload->moveTo($target);
            $this->assertSame('chunk', (string) file_get_contents($target));
        } finally {
            @unlink($target);
        }
    }
}
