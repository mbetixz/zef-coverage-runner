<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Transport\CancellationTokenInterface;
use Zef\Framework\Transport\NeverCancelledToken;
use Zef\Framework\Transport\RemoteRequest;
use Zef\Framework\Transport\RemoteTransportInterface;
use Zef\Framework\Transport\RemoteTransportResult;
use Zef\Framework\Transport\Transport;
use Zef\Framework\Transport\TransportContext;
use Zef\Framework\Transport\TransportOutcome;

/**
 * Batch 6 coverage: Transport namespace.
 *
 * Covers TransportContext bounds/cancellation/expiry, RemoteRequest and
 * RemoteTransportResult contract validation, the TransportOutcome enum, the
 * Transport aggregate requires, NeverCancelledToken and a full
 * RemoteTransportInterface send() round-trip through an anonymous adapter.
 * All assertions are deterministic (fixed values, no wall clock).
 */
final class Batch6TransportTest extends TestCase
{
    public function testTransportAggregateFileLoadsAllDeclarations(): void
    {
        // Transport.php is the compatibility aggregate; the file itself is not a class.
        $this->assertFileExists(dirname(__DIR__, 2) . '/src/Framework/Transport/Transport.php');
        $this->assertTrue(interface_exists(RemoteTransportInterface::class));
        $this->assertTrue(enum_exists(TransportOutcome::class));
        $this->assertTrue(class_exists(TransportContext::class));
        $this->assertTrue(class_exists(RemoteRequest::class));
        $this->assertTrue(class_exists(RemoteTransportResult::class));
        $this->assertTrue(class_exists(NeverCancelledToken::class));
    }

    public function testTransportOutcomeIsStringBackedWithExpectedValues(): void
    {
        $cases = TransportOutcome::cases();
        $this->assertCount(10, $cases);
        $this->assertSame('success', TransportOutcome::SUCCESS->value);
        $this->assertSame('rejected', TransportOutcome::REJECTED->value);
        $this->assertSame('timeout', TransportOutcome::TIMEOUT->value);
        $this->assertSame('unavailable', TransportOutcome::UNAVAILABLE->value);
        $this->assertSame('transport_failure', TransportOutcome::TRANSPORT_FAILURE->value);
        $this->assertSame('authentication_failure', TransportOutcome::AUTHENTICATION_FAILURE->value);
        $this->assertSame('authorization_failure', TransportOutcome::AUTHORIZATION_FAILURE->value);
        $this->assertSame('remote_processing_failure', TransportOutcome::REMOTE_PROCESSING_FAILURE->value);
        $this->assertSame('indeterminate', TransportOutcome::INDETERMINATE->value);
        $this->assertSame('cancelled', TransportOutcome::CANCELLED->value);
        $this->assertSame(TransportOutcome::SUCCESS, TransportOutcome::from('success'));
    }

    public function testTransportContextHappyPathAndPublicProperties(): void
    {
        $context = new TransportContext(5_000, 10_000);
        $this->assertSame(5_000, $context->timeoutMs);
        $this->assertSame(10_000, $context->deadlineMs);
        $this->assertNull($context->cancellation);
        $this->assertFalse($context->isCancelled());
        $this->assertFalse($context->hasExpired(9_999));
        $this->assertTrue($context->hasExpired(10_000));
        $this->assertSame(5_000, $context->remainingMs(0));
        $this->assertSame(4_000, $context->remainingMs(6_000), 'deadline budget governs once deadline - now < timeoutMs');
        $this->assertSame(0, $context->remainingMs(10_000));
        $this->assertSame(0, $context->remainingMs(50_000), 'floored at 0 past deadline');
    }

    public function testTransportContextTimeoutGovernsRemainingWhenBelowDeadline(): void
    {
        $context = new TransportContext(100, 300_000);
        $this->assertSame(100, $context->remainingMs(0));
        $this->assertSame(100, $context->remainingMs(1), 'timeoutMs wins while deadline - now stays above it');
        $this->assertSame(100, $context->remainingMs(299_900));
        $this->assertSame(50, $context->remainingMs(299_950));
    }

    public function testTransportContextConstructorRejectsOutOfBounds(): void
    {
        foreach ([0, -1, 300_001] as $timeoutMs) {
            try {
                new TransportContext($timeoutMs, 0);
                $this->fail("timeoutMs $timeoutMs must throw");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            new TransportContext(1_000, -1);
            $this->fail('negative deadlineMs must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        // Boundary values construct without throwing; the construction is the assertion.
        new TransportContext(1, 0);
        new TransportContext(300_000, 300_000);
        $this->addToAssertionCount(2);
    }

    public function testTransportContextRejectsNegativeMonotonicReadings(): void
    {
        $context = new TransportContext(1_000, 2_000);
        try {
            $context->hasExpired(-1);
            $this->fail('negative hasExpired input must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        $context->remainingMs(-1);
    }

    public function testTransportContextHonoursCancellationToken(): void
    {
        $never = new NeverCancelledToken();
        $this->assertFalse($never->isCancellationRequested());
        $context = new TransportContext(1_000, 2_000, $never);
        $this->assertFalse($context->isCancelled());

        $cancelled = new class implements CancellationTokenInterface {
            #[Override]
            public function isCancellationRequested(): bool
            {
                return true;
            }
        };
        $context2 = new TransportContext(1_000, 2_000, $cancelled);
        $this->assertTrue($context2->isCancelled());
    }

    public function testRemoteRequestValidConstruction(): void
    {
        $request = new RemoteRequest('orders.create', '{"id":1}', ['tenant' => 'acme', 'attempt' => 3, 'flag' => true, 'maybe' => null]);
        $this->assertSame('orders.create', $request->operation);
        $this->assertSame('{"id":1}', $request->payload);
        $this->assertCount(4, $request->metadata);
        $this->assertSame('acme', $request->metadata['tenant']);

        $empty = new RemoteRequest('  orders.list  ', '', []);
        $this->assertSame('  orders.list  ', $empty->operation, 'trim only guards emptiness, value is kept verbatim');
    }

    public function testRemoteRequestRejectsEmptyOperation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RemoteRequest('   ', 'payload');
    }

    public function testRemoteRequestRejectsOversizedPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RemoteRequest('op', str_repeat('x', 1_048_577));
    }

    public function testRemoteRequestMetadataBoundsAreEnforced(): void
    {
        $tooMany = [];
        for ($i = 0; $i < 33; ++$i) {
            $tooMany["k$i"] = $i;
        }
        try {
            new RemoteRequest('op', '', $tooMany);
            $this->fail('>32 metadata entries must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new RemoteRequest('op', '', [0 => 'numeric-key']);
            $this->fail('numeric metadata key must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new RemoteRequest('op', '', [' ' => 'blank-key']);
            $this->fail('blank metadata key must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new RemoteRequest('op', '', [str_repeat('k', 129) => 'v']);
            $this->fail('>128 byte metadata key must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new RemoteRequest('op', '', ['k' => ['nested']]);
            $this->fail('non-scalar metadata value must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        new RemoteRequest('op', '', ['k' => str_repeat('v', 1_025)]);
    }

    public function testRemoteTransportResultVariantsAndIsDefinitive(): void
    {
        $ok = new RemoteTransportResult(TransportOutcome::SUCCESS, '{"ok":true}', ['k' => 1]);
        $this->assertTrue($ok->isDefinitive());
        $this->assertSame('{"ok":true}', $ok->payload);
        $this->assertSame(['k' => 1], $ok->metadata);

        foreach ([
            TransportOutcome::REJECTED,
            TransportOutcome::TIMEOUT,
            TransportOutcome::UNAVAILABLE,
            TransportOutcome::TRANSPORT_FAILURE,
            TransportOutcome::AUTHENTICATION_FAILURE,
            TransportOutcome::AUTHORIZATION_FAILURE,
            TransportOutcome::REMOTE_PROCESSING_FAILURE,
            TransportOutcome::CANCELLED,
        ] as $outcome) {
            $this->assertTrue((new RemoteTransportResult($outcome))->isDefinitive());
        }
        $indeterminate = new RemoteTransportResult(TransportOutcome::INDETERMINATE, null, []);
        $this->assertFalse($indeterminate->isDefinitive());
        $this->assertNull($indeterminate->payload);
    }

    public function testRemoteTransportResultValidation(): void
    {
        try {
            new RemoteTransportResult(TransportOutcome::SUCCESS, str_repeat('x', 1_048_577));
            $this->fail('oversized payload must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $tooMany = [];
        for ($i = 0; $i < 33; ++$i) {
            $tooMany["k$i"] = $i;
        }
        try {
            new RemoteTransportResult(TransportOutcome::SUCCESS, null, $tooMany);
            $this->fail('>32 metadata entries must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new RemoteTransportResult(TransportOutcome::SUCCESS, null, [5 => 'x']);
            $this->fail('numeric metadata key must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new RemoteTransportResult(TransportOutcome::SUCCESS, null, [str_repeat('k', 129) => 'v']);
            $this->fail('oversized metadata key must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new RemoteTransportResult(TransportOutcome::SUCCESS, null, ['k' => new stdClass()]);
            $this->fail('non-scalar metadata value must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        new RemoteTransportResult(TransportOutcome::SUCCESS, null, ['k' => str_repeat('v', 1_025)]);
    }

    public function testAnonymousTransportAdapterRoundTrip(): void
    {
        $adapter = new class implements RemoteTransportInterface {
            #[Override]
            public function send(RemoteRequest $request, TransportContext $context): RemoteTransportResult
            {
                if ($context->isCancelled()) {
                    return new RemoteTransportResult(TransportOutcome::CANCELLED);
                }
                if ($context->hasExpired(0)) {
                    return new RemoteTransportResult(TransportOutcome::TIMEOUT);
                }
                if ($request->operation === 'fail') {
                    return new RemoteTransportResult(TransportOutcome::INDETERMINATE);
                }
                return new RemoteTransportResult(TransportOutcome::SUCCESS, 'reply:' . $request->operation, ['echo' => $request->metadata['tag'] ?? null]);
            }
        };

        $request = new RemoteRequest('ping', '', ['tag' => 'b6']);
        $context = new TransportContext(1_000, 2_000, new NeverCancelledToken());
        $result = $adapter->send($request, $context);
        $this->assertSame(TransportOutcome::SUCCESS, $result->outcome);
        $this->assertSame('reply:ping', $result->payload);
        $this->assertSame('b6', $result->metadata['echo']);
        $this->assertTrue($result->isDefinitive());

        $indeterminate = $adapter->send(new RemoteRequest('fail', ''), $context);
        $this->assertSame(TransportOutcome::INDETERMINATE, $indeterminate->outcome);
        $this->assertFalse($indeterminate->isDefinitive());

        $cancelled = $adapter->send($request, new TransportContext(1_000, 2_000, new class implements CancellationTokenInterface {
            #[Override]
            public function isCancellationRequested(): bool
            {
                return true;
            }
        }));
        $this->assertSame(TransportOutcome::CANCELLED, $cancelled->outcome);
    }
}
