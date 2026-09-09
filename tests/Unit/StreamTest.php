<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Http\Stream;

final class StreamTest extends TestCase
{
    public function testFromStringIsReadableFromStart(): void
    {
        $stream = Stream::fromString('hello world');

        $this->assertSame(11, $stream->getSize());
        $this->assertSame(0, $stream->tell());
        $this->assertFalse($stream->eof());
        $this->assertTrue($stream->isReadable());
        $this->assertTrue($stream->isSeekable());
        $this->assertSame('hello world', (string) $stream);
    }

    public function testReadReturnsContentAndAdvancesPointer(): void
    {
        $stream = Stream::fromString('hello world');

        $this->assertSame('hello', $stream->read(5));
        $this->assertSame(5, $stream->tell());
        $this->assertSame(' world', $stream->getContents());
        $this->assertTrue($stream->eof());
    }

    public function testWriteAppendsAndReturnsBytesWritten(): void
    {
        $stream = Stream::fromString('');
        $stream->write('abc'); // pointer is now at the end

        $this->assertTrue($stream->isWritable());
        $this->assertSame('abc', (string) $stream);
    }

    public function testSeekAndRewind(): void
    {
        $stream = Stream::fromString('hello world');

        $stream->seek(6);
        $this->assertSame('world', $stream->read(5));

        $stream->rewind();
        $this->assertSame(0, $stream->tell());
        $this->assertSame('hello', $stream->read(5));
    }

    public function testToStringRewindsBeforeReading(): void
    {
        $stream = Stream::fromString('hello world');
        $stream->read(6); // advance pointer

        $this->assertSame('hello world', (string) $stream);
        // __toString leaves the pointer at EOF after reading
        $this->assertTrue($stream->eof());
    }

    public function testCloseDetachesAndNullsSize(): void
    {
        $stream = Stream::fromString('hello');
        $stream->close();

        $this->assertNull($stream->getSize());
        $this->assertNull($stream->getMetadata('mode'));
        $this->assertSame('', (string) $stream);
    }

    public function testDetachReturnsResourceAndDetaches(): void
    {
        $stream = Stream::fromString('hello');
        $resource = $stream->detach();

        $this->assertIsResource($resource);
        $this->assertNull($stream->getSize());
    }

    public function testGetMetadataReturnsAllOrSingleKey(): void
    {
        $stream = Stream::fromString('hello');
        $all = $stream->getMetadata();

        $this->assertIsArray($all);
        $this->assertArrayHasKey('mode', $all);
        $this->assertSame('w+b', $stream->getMetadata('mode'));
        $this->assertNull($stream->getMetadata('nonexistent-key'));
    }

    public function testWriteOnReadOnlyStreamThrows(): void
    {
        $resource = fopen('php://temp', 'rb');
        $this->assertNotFalse($resource);
        $stream = new Stream($resource);

        $this->assertFalse($stream->isWritable());
        $this->expectException(\RuntimeException::class);
        $stream->write('x');
    }

    public function testWriteOnReadOnlyDetachedThrows(): void
    {
        $stream = Stream::fromString('hello');
        $stream->detach();

        $this->expectException(\RuntimeException::class);
        $stream->write('x');
    }

    public function testGetContentsOnUnreadableStreamThrows(): void
    {
        $resource = fopen('/dev/null', 'wb');
        $this->assertNotFalse($resource);
        $stream = new Stream($resource);

        $this->expectException(\RuntimeException::class);
        $stream->getContents();
    }

    public function testToStringOnUnreadableStreamReturnsEmpty(): void
    {
        $resource = fopen('/dev/null', 'wb');
        $this->assertNotFalse($resource);
        $stream = new Stream($resource);

        $this->assertSame('', (string) $stream);
    }

    public function testSeekOnDetachedStreamThrows(): void
    {
        $stream = Stream::fromString('hello');
        $stream->detach();

        $this->expectException(\RuntimeException::class);
        $stream->seek(1);
    }

    public function testTellOnDetachedStreamThrows(): void
    {
        $stream = Stream::fromString('hello');
        $stream->detach();

        $this->expectException(\RuntimeException::class);
        $stream->tell();
    }

    public function testReadWithNegativeLengthThrows(): void
    {
        $stream = Stream::fromString('hello');

        $this->expectException(\InvalidArgumentException::class);
        $stream->read(-1);
    }

    public function testReadZeroReturnsEmptyString(): void
    {
        $stream = Stream::fromString('hello');

        $this->assertSame('', $stream->read(0));
    }

    public function testConstructorRejectsNonResource(): void
    {
        $reflection = new \ReflectionClass(Stream::class);

        $this->expectException(\InvalidArgumentException::class);
        $reflection->newInstanceArgs(['not-a-resource']);
    }

    public function testEofOnEmptyDetachedStreamIsTrue(): void
    {
        $stream = Stream::fromString('');
        $stream->close();

        $this->assertTrue($stream->eof());
    }
}
