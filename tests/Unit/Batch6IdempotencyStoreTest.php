<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Delivery\BoundedInMemoryIdempotencyStore;
use Zef\Framework\Delivery\IdempotencyClaim;
use Zef\Framework\Delivery\IdempotencyRecord;
use Zef\Framework\Transport\RemoteTransportResult;
use Zef\Framework\Transport\TransportOutcome;

/**
 * Batch 6 coverage: Delivery\BoundedInMemoryIdempotencyStore remaining
 * branches (complete() on an absent key, complete() overwrite replay, the
 * IdempotencyRecord value object and stricter validation bounds).
 */
final class Batch6IdempotencyStoreTest extends TestCase
{
    private function makeResult(string $payload = 'ok'): RemoteTransportResult
    {
        return new RemoteTransportResult(TransportOutcome::SUCCESS, $payload);
    }

    public function testCompleteOnAbsentKeyRecordsResultImmediately(): void
    {
        $store = new BoundedInMemoryIdempotencyStore();
        $store->complete('idem-absent-01', 'fp-absent-01', $this->makeResult('first'));

        $claim = $store->claim('idem-absent-01', 'fp-absent-01');
        $this->assertSame(IdempotencyClaim::DUPLICATE, $claim, 'recorded by complete() is visible to claim()');
        $this->assertSame('first', $store->completedResult('idem-absent-01', 'fp-absent-01')?->payload);
    }

    public function testCompleteOverwritesPreviousResultForSameFingerprint(): void
    {
        $store = new BoundedInMemoryIdempotencyStore();
        $this->assertSame(IdempotencyClaim::NEW, $store->claim('idem-over-01', 'fp-over-01'));
        $store->complete('idem-over-01', 'fp-over-01', $this->makeResult('v1'));
        $store->complete('idem-over-01', 'fp-over-01', $this->makeResult('v2'));
        $this->assertSame('v2', $store->completedResult('idem-over-01', 'fp-over-01')?->payload);
    }

    public function testCompletedResultWhileInFlightReturnsNull(): void
    {
        $store = new BoundedInMemoryIdempotencyStore();
        $store->claim('idem-flight-01', 'fp-flight-01');
        $this->assertNull($store->completedResult('idem-flight-01', 'fp-flight-01'));
        $this->assertNull($store->completedResult('idem-never-01', 'fp-flight-01'), 'absent key yields null');
    }

    public function testCapacityBoundAppliesToCompleteAsWell(): void
    {
        $store = new BoundedInMemoryIdempotencyStore(1);
        $store->complete('idem-cap-01', 'fp-cap-01', $this->makeResult());
        $this->expectException(RuntimeException::class);
        $store->complete('idem-cap-02', 'fp-cap-02', $this->makeResult());
    }

    public function testValidationBoundsForKeyAndFingerprint(): void
    {
        $store = new BoundedInMemoryIdempotencyStore();
        $keyTooLong = str_repeat('k', 129);
        $fpTooLong = str_repeat('f', 129);

        try {
            $store->claim($keyTooLong, 'fp');
            $this->fail('overlong key claim must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $store->complete('idem-valid-01', $fpTooLong, $this->makeResult());
            $this->fail('overlong fingerprint complete must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $store->completedResult('', 'fp');
            $this->fail('empty key completedResult must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $store->complete('idem-valid-02', '', $this->makeResult());
            $this->fail('empty fingerprint complete must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        // Exact boundary lengths (128) are accepted.
        $store->claim(str_repeat('k', 128), str_repeat('f', 128));
        $this->addToAssertionCount(1);
    }

    public function testMaxRecordsBoundaries(): void
    {
        // Boundary capacities construct without throwing; the construction is the assertion.
        new BoundedInMemoryIdempotencyStore(1);
        new BoundedInMemoryIdempotencyStore(65_536);
        $this->addToAssertionCount(2);
        try {
            new BoundedInMemoryIdempotencyStore(0);
            $this->fail('zero capacity must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new BoundedInMemoryIdempotencyStore(65_537);
            $this->fail('capacity above 65536 must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testIdempotencyRecordValueObjectExposesProperties(): void
    {
        $result = $this->makeResult('payload');
        $inFlight = new IdempotencyRecord('key-0001', 'fp-0001', null);
        $this->assertSame('key-0001', $inFlight->idempotencyKey);
        $this->assertSame('fp-0001', $inFlight->operationFingerprint);
        $this->assertNull($inFlight->result);

        $completed = new IdempotencyRecord('key-0002', 'fp-0002', $result);
        $this->assertSame($result, $completed->result);
    }
}
