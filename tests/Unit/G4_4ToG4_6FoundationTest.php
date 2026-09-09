<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\ConfigurationGovernance;
use Zef\Framework\Config\EnvironmentSecretProvider;
use Zef\Framework\Config\SecretValue;
use Zef\Framework\Message\InProcessMessageBus;
use Zef\Framework\Message\JsonMessageSerializer;
use Zef\Framework\Message\MessageContext;
use Zef\Framework\Message\MessageEnvelope;
use Zef\Framework\Message\MessageHandlerInterface;
use Zef\Framework\Resource\InMemoryAdmissionController;
use Zef\Framework\Resource\ResourceBudget;

function g_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

final class G4_4ToG4_6FoundationTest extends TestCase
{
    public function testLegacyBehavior(): void
    {
        $g = new ConfigurationGovernance();
        $g->addValidator('required', static function(array $v): void { if (!isset($v['app'])) throw new InvalidArgumentException('missing app'); });
        $s = $g->publish(['app'=>'zef'], 2);
        g_assert($s->version === 2 && $g->current()?->values['app'] === 'zef', 'configuration publication failed');
        $secret = new SecretValue('abc'); g_assert((string)$secret === '[REDACTED]' && $secret->reveal() === 'abc', 'secret redaction failed');
        $provider = new EnvironmentSecretProvider(); putenv('ZEF_TEST_SECRET=present'); g_assert($provider->get('ZEF_TEST_SECRET')?->reveal() === 'present', 'environment secret provider failed'); putenv('ZEF_TEST_SECRET');

        $c = new InMemoryAdmissionController(new ResourceBudget(1, 1));
        $d1 = $c->admit(); $d2 = $c->admit(); g_assert($d1->admitted && !$d2->admitted && $d2->reason === 'in_flight_limit', 'admission limit failed'); $c->complete();
        $q = $c->queue(); g_assert($q->admitted, 'queue admission failed'); $c->dequeue(); $snap = $c->snapshot(); g_assert($snap->completed === 1 && $snap->queued === 0, 'resource snapshot failed');

        $handler = new class implements MessageHandlerInterface {
            public bool $called = false;
            #[\Override]
            public function __invoke(MessageEnvelope $message, MessageContext $context): mixed { $this->called = $message->payload === ['ok'=>true]; return null; }
        };
        $bus = new InProcessMessageBus(['demo.event'=>$handler]);
        $msg = new MessageEnvelope('msg12345', 'demo.event', ['ok'=>true]);
        $result = $bus->dispatch($msg); g_assert($result->accepted && $handler->called, 'in-process message dispatch failed');
        $serializer = new JsonMessageSerializer(); $roundTrip = $serializer->deserialize($serializer->serialize($msg)); g_assert($roundTrip->payload === ['ok'=>true], 'JSON message round-trip failed');
        try { $serializer->serialize(new MessageEnvelope('msg12346','demo.event',new stdClass())); throw new RuntimeException('unsafe payload accepted'); } catch (InvalidArgumentException) {}
        $this->addToAssertionCount(1);
    }
}
