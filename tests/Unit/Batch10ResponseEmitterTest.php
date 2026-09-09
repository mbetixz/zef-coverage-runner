<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\Stream;
use Zef\Framework\ResponseEmitter;

/**
 * Batch 10 coverage: ResponseEmitter body/status semantics (previously 0%).
 * Statuses that never carry a body (204/205/304), the suppressBody flag, the
 * default keep-open contract, the explicit closeBody contract and multi-chunk
 * body streaming. All output is captured with ob_start(); header()/echo are
 * safe under CLI + output buffering (same pattern as the existing self-test).
 */
final class Batch10ResponseEmitterTest extends TestCase
{
    /** @return string captured output */
    private function emit(ResponseInterface $response, bool $closeBody = false, bool $suppressBody = false): string
    {
        ob_start();
        try {
            (new ResponseEmitter())->emit($response, $closeBody, $suppressBody);
        } finally {
            $output = (string) ob_get_clean();
        }
        return $output;
    }

    public function testEmitWritesBodyAndKeepsStreamOpenByDefault(): void
    {
        $body = Stream::fromString('hello');
        $response = (new Response(200, ['X-Zef' => '1']))->withBody($body);
        self::assertSame('hello', $this->emit($response));
        self::assertTrue($body->isReadable());
    }

    public function testEmitClosesBodyWhenRequested(): void
    {
        $body = Stream::fromString('close-me');
        $response = (new Response(200))->withBody($body);
        self::assertSame('close-me', $this->emit($response, true));
        try {
            $body->tell();
            self::fail('closed stream must reject tell()');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testEmitSkipsBodyForNoBodyStatuses(): void
    {
        foreach ([204, 205, 304] as $status) {
            $body = Stream::fromString('must-not-leak');
            $response = (new Response($status))->withBody($body);
            self::assertSame('', $this->emit($response), "status {$status} must not emit a body");
        }
    }

    public function testEmitSuppressBodyFlag(): void
    {
        $body = Stream::fromString('secret');
        $response = (new Response(200))->withBody($body);
        self::assertSame('', $this->emit($response, false, true));
        self::assertTrue($body->isReadable());
    }

    public function testEmitStreamsLargeBodyInChunks(): void
    {
        $payload = str_repeat('x', 20000);
        $response = (new Response(200))->withBody(Stream::fromString($payload));
        self::assertSame(20000, strlen($this->emit($response)));
    }
}
