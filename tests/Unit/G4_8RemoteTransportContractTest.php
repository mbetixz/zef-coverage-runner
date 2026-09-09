<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Transport\CancellationTokenInterface;
use Zef\Framework\Transport\RemoteRequest;
use Zef\Framework\Transport\RemoteTransportInterface;
use Zef\Framework\Transport\RemoteTransportResult;
use Zef\Framework\Transport\TransportContext;
use Zef\Framework\Transport\TransportOutcome;

final class G4_8RemoteTransportContractTest extends TestCase
{
    public function testContractIsExplicitAndBounded(): void
    {
        $request = new RemoteRequest('health.check', '{}', ['traceparent' => '00-abc-def-01']);
        $context = new TransportContext(1000, 1000);
        $result = new RemoteTransportResult(TransportOutcome::SUCCESS);

        self::assertSame('health.check', $request->operation);
        self::assertSame(900, $context->remainingMs(100));
        self::assertFalse($context->hasExpired(999));
        self::assertTrue($context->hasExpired(1000));
        self::assertTrue($result->isDefinitive());
        self::assertFalse((new RemoteTransportResult(TransportOutcome::INDETERMINATE))->isDefinitive());
        self::assertTrue(interface_exists(RemoteTransportInterface::class));
    }

    public function testCancellationIsExplicitAndDoesNotMutateOutcome(): void
    {
        $token = new class implements CancellationTokenInterface {
            #[\Override]
            public function isCancellationRequested(): bool { return true; }
        };
        $context = new TransportContext(50, 50, $token);

        self::assertTrue($context->isCancelled());
        self::assertSame(TransportOutcome::INDETERMINATE, (new RemoteTransportResult(TransportOutcome::INDETERMINATE))->outcome);
    }

    public function testInvalidAndOversizedInputsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RemoteRequest('', '');
    }

    public function testPayloadLimitIsEnforced(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RemoteRequest('payload.test', str_repeat('x', 1_048_577));
    }

    public function testMetadataLimitsAreEnforced(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RemoteRequest('metadata.test', '', array_fill(0, 33, 'x'));
    }
}
