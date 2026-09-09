<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Message\MessageBusInterface;
use Zef\Framework\Message\MessageContext;
use Zef\Framework\Message\MessageEnvelope;
use Zef\Framework\Message\MessageHandlerInterface;
use Zef\Framework\Message\MessageMiddlewareInterface;
use Zef\Framework\Message\MessageResult;
use Zef\Framework\Message\MessageSerializerInterface;
use Zef\Framework\Message\MessageTransportInterface;

final class MessageFoundationBus implements MessageBusInterface
{
    #[\Override]
    public function dispatch(MessageEnvelope $message, ?MessageContext $context = null): MessageResult
    {
        return new MessageResult($message->messageId, true);
    }
}

final class MessageFoundationTest extends TestCase
{
    public function testLegacyBehavior(): void
    {
        $assert = static function (bool $cond, string $msg): void { if (!$cond) throw new RuntimeException($msg); };

        $envelope = new MessageEnvelope(
            'msg-20260831',
            'zef.demo.created',
            ['id' => 42],
            ['content-type' => 'application/json'],
        );
        $context = new MessageContext('corr-20260831', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        $result = (new MessageFoundationBus())->dispatch($envelope, $context);
        $assert($result->accepted === true, 'dispatch accepted flag');
        $assert($result->messageId === $envelope->messageId, 'dispatch messageId echo');
        $assert(is_array($envelope->payload) && $envelope->payload['id'] === 42, 'payload id');

        $interfaces = [
            MessageBusInterface::class,
            MessageSerializerInterface::class,
            MessageHandlerInterface::class,
            MessageMiddlewareInterface::class,
            MessageTransportInterface::class,
        ];
        foreach ($interfaces as $interface) {
            $assert(interface_exists($interface), 'interface exists: '.$interface);
            $assert(count((new ReflectionClass($interface))->getMethods()) >= 1, 'interface methods: '.$interface);
        }

        $invalid = false;
        try { new MessageEnvelope('bad', 'zef.demo', null); } catch (InvalidArgumentException) { $invalid = true; }
        $assert($invalid, 'null payload envelope rejected');

        $invalid = false;
        try { new MessageContext(null, 'not-a-trace'); } catch (InvalidArgumentException) { $invalid = true; }
        $assert($invalid, 'invalid context rejected');
        $this->addToAssertionCount(1);
    }
}
