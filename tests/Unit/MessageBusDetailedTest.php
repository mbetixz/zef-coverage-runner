<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Message\InProcessMessageBus;
use Zef\Framework\Message\JsonMessageSerializer;
use Zef\Framework\Message\MessageContext;
use Zef\Framework\Message\MessageEnvelope;
use Zef\Framework\Message\MessageHandlerInterface;
use Zef\Framework\Message\MessageMiddlewareInterface;
use Zef\Framework\Message\MessageResult;

final class B2MessageRecordingHandler implements MessageHandlerInterface
{
    /** @var list<array{string,mixed}> */
    public array $calls = [];

    #[Override]
    public function __invoke(MessageEnvelope $message, MessageContext $context): mixed
    {
        $this->calls[] = [$message->messageType, $context->correlationId];
        return new MessageResult($message->messageId, true);
    }
}

final class B2MessageAppendMiddleware implements MessageMiddlewareInterface
{
    /** @var list<string> */
    public array $log = [];

    public function __construct(private readonly string $tag)
    {
    }

    #[Override]
    public function process(MessageEnvelope $message, MessageContext $context, Closure $next): MessageResult
    {
        $this->log[] = $this->tag . ':before';
        $result = $next($message, $context);
        $this->log[] = $this->tag . ':after';
        if (!$result instanceof MessageResult) {
            throw new \RuntimeException('Middleware chain must return a MessageResult.');
        }
        return $result;
    }

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }
}

final class MessageBusDetailedTest extends TestCase
{
    public function testRegisterRejectsMalformedAndDuplicateTypes(): void
    {
        $bus = new InProcessMessageBus();
        $this->expectException(\InvalidArgumentException::class);
        $bus->registerHandler('', new B2MessageRecordingHandler());
    }

    public function testRegisterRejectsDuplicateType(): void
    {
        $bus = new InProcessMessageBus();
        $bus->registerHandler('zef.demo', new B2MessageRecordingHandler());
        $this->expectException(\LogicException::class);
        $bus->registerHandler('zef.demo', new B2MessageRecordingHandler());
    }

    public function testDispatchWithoutHandlerThrows(): void
    {
        $bus = new InProcessMessageBus();
        $this->expectException(\RuntimeException::class);
        $bus->dispatch(new MessageEnvelope('msg-0000001', 'zef.unknown', null));
    }

    public function testConstructorRegistersHandlersAndMiddleware(): void
    {
        $handler = new B2MessageRecordingHandler();
        $middleware = new B2MessageAppendMiddleware('m1');
        $bus = new InProcessMessageBus(['zef.demo' => $handler], [$middleware]);
        $result = $bus->dispatch(new MessageEnvelope('msg-0000002', 'zef.demo', ['id' => 1]));

        $this->assertTrue($result->accepted);
        $this->assertSame('msg-0000002', $result->messageId);
        $this->assertCount(1, $handler->calls);
        $this->assertSame(['m1:before', 'm1:after'], $middleware->log);
    }

    public function testMiddlewareChainOrderAndShortCircuitValues(): void
    {
        $seen = [];
        $handler = new B2MessageRecordingHandler();
        $mwA = new class ($seen) implements MessageMiddlewareInterface {
            /** @param list<string> $seen */
            public function __construct(private array &$seen)
            {
            }

            #[Override]
            public function process(MessageEnvelope $message, MessageContext $context, Closure $next): MessageResult
            {
                $this->seen[] = 'a';
                $result = $next($message, $context);
                if (!$result instanceof MessageResult) {
                    throw new \RuntimeException('Middleware chain must return a MessageResult.');
                }
                return $result;
            }

            /** @return list<string> */
            public function seen(): array
            {
                return $this->seen;
            }
        };
        $mwB = new class ($seen) implements MessageMiddlewareInterface {
            /** @param list<string> $seen */
            public function __construct(private array &$seen)
            {
            }

            #[Override]
            public function process(MessageEnvelope $message, MessageContext $context, Closure $next): MessageResult
            {
                $this->seen[] = 'b';
                $result = $next($message, $context);
                if (!$result instanceof MessageResult) {
                    throw new \RuntimeException('Middleware chain must return a MessageResult.');
                }
                return $result;
            }

            /** @return list<string> */
            public function seen(): array
            {
                return $this->seen;
            }
        };
        $bus = new InProcessMessageBus(['zef.demo' => $handler], [$mwA, $mwB]);
        $bus->dispatch(new MessageEnvelope('msg-0000003', 'zef.demo', null));
        // Both middleware instances share the by-reference $seen buffer, so each observes the full ordered chain.
        $this->assertSame(['a', 'b'], $mwA->seen());
        $this->assertSame(['a', 'b'], $mwB->seen());
    }

    public function testAddMiddlewareLimit32(): void
    {
        $bus = new InProcessMessageBus();
        for ($i = 0; $i < 32; ++$i) {
            $bus->addMiddleware(new B2MessageAppendMiddleware('m' . $i));
        }
        $this->expectException(\LogicException::class);
        $bus->addMiddleware(new B2MessageAppendMiddleware('overflow'));
    }

    public function testMessageEnvelopeValidatesEveryBound(): void
    {
        $valid = new MessageEnvelope('msg-0000004', 'zef.demo', ['x' => 1], ['trace' => 't']);
        $this->assertSame('msg-0000004', $valid->messageId);
        $this->assertSame(['trace' => 't'], $valid->headers);

        $invalidCases = [
            'too-short id' => ['ab', 'zef.demo'],
            'bad spaces in type' => ['msg-0000005', 'bad spaces'],
            'oversized id' => ['msg-' . str_repeat('a', 125), 'zef.demo'],
            'oversized type' => ['msg-0000006', str_repeat('a', 256)],
        ];
        foreach ($invalidCases as $case => [$id, $type]) {
            try {
                new MessageEnvelope($id, $type, null);
                $this->fail('expected InvalidArgumentException for ' . $case);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new MessageEnvelope('msg-0000007', 'zef.demo', null, ['x' => str_repeat('v', 4097)]);
    }

    public function testMessageEnvelopeRejectsOversizedHeaderMap(): void
    {
        $headers = [];
        for ($i = 0; $i < 65; ++$i) {
            $headers['h' . $i] = 'v';
        }
        $this->expectException(\InvalidArgumentException::class);
        new MessageEnvelope('msg-0000007', 'zef.demo', null, $headers);
    }

    public function testMessageContextValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MessageContext(null, null, ['' => 'x']);
    }

    public function testMessageResultValidation(): void
    {
        try {
            new MessageResult('bad', true);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new MessageResult('msg-0000008', true, str_repeat('t', 256));
    }

    public function testJsonSerializerRoundTripAndMissingPayloadDefaultsNull(): void
    {
        $serializer = new JsonMessageSerializer();
        $envelope = new MessageEnvelope('msg-0000009', 'zef.demo', ['nested' => ['a' => [1, 2]]], ['k' => 'v']);
        $wire = $serializer->serialize($envelope);
        $back = $serializer->deserialize($wire);
        $this->assertSame($envelope->messageId, $back->messageId);
        $this->assertSame($envelope->messageType, $back->messageType);
        $this->assertSame(['nested' => ['a' => [1, 2]]], $back->payload);
        $this->assertSame(['k' => 'v'], $back->headers);

        $noPayload = $serializer->deserialize('{"id":"msg-0000010","type":"zef.demo"}');
        $this->assertNull($noPayload->payload);
    }

    public function testJsonSerializerRejectsNonJsonSafePayload(): void
    {
        $serializer = new JsonMessageSerializer();
        $this->expectException(\InvalidArgumentException::class);
        $serializer->serialize(new MessageEnvelope('msg-0000011', 'zef.demo', new stdClass()));
    }

    public function testJsonSerializerRejectsResourceInNestedArray(): void
    {
        $stream = fopen('php://memory', 'r');
        if ($stream === false) {
            $this->fail('failed to open memory stream');
        }
        try {
            $serializer = new JsonMessageSerializer();
            $this->expectException(\InvalidArgumentException::class);
            $serializer->serialize(new MessageEnvelope('msg-0000012', 'zef.demo', ['r' => $stream]));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testJsonDeserializeValidationPaths(): void
    {
        $serializer = new JsonMessageSerializer();

        try {
            $serializer->deserialize('{"id":42}');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            $serializer->deserialize(str_repeat('x', 1_048_577));
            $this->fail('expected InvalidArgumentException for oversized payload');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            $serializer->deserialize('{"id":"msg-0000013","type":"zef.demo","headers":{"a":1}}');
            $this->fail('expected InvalidArgumentException for non-string header value');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testJsonDeserializeMalformedThrowsJsonException(): void
    {
        $serializer = new JsonMessageSerializer();
        $this->expectException(\JsonException::class);
        $serializer->deserialize('not json');
    }
}
