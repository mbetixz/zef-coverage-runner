<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Observability\CorrelationContext;
use Zef\Framework\Observability\CorrelationHeaders;
use Zef\Framework\Observability\CorrelationPropagator;

/**
 * Batch 6 coverage: Observability\Correlation* remaining branches.
 *
 * Extends G4_8CorrelationContextTest by exercising the validation error
 * paths, propagationBytes accounting, wire-size hard limit, the
 * CorrelationHeaders constructor bounds and extract()'s tolerant parsing.
 * Deterministic: fixed hex values and fixed-size payloads only.
 */
final class Batch6CorrelationContextTest extends TestCase
{
    private const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const SPAN = '00f067aa0ba902b7';

    public function testPropagationBytesCountsTraceparentAndTracestate(): void
    {
        $withoutState = new CorrelationContext(self::TRACE, self::SPAN, '01', null, 'op.create');
        $this->assertSame(55, $withoutState->propagationBytes());

        $withState = new CorrelationContext(self::TRACE, self::SPAN, '01', 'vendor=value', 'op.create');
        $this->assertSame(55 + 1 + strlen('vendor=value'), $withState->propagationBytes());
        $this->assertSame(55, strlen($withState->traceParent()));
    }

    public function testConstructorRejectsInvalidTraceId(): void
    {
        foreach ([
            'not-hex-at-all-aaaaaaaaaaaaaaaaaaaaaaa',
            str_repeat('0', 32),
            str_repeat('g', 32),
            str_repeat('a', 31),
        ] as $bad) {
            try {
                new CorrelationContext($bad, self::SPAN, '01', null, 'op.create');
                $this->fail("traceId '$bad' must throw");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testConstructorRejectsInvalidSpanId(): void
    {
        foreach ([
            str_repeat('0', 16),
            str_repeat('a', 15),
            str_repeat('x', 16),
        ] as $bad) {
            try {
                new CorrelationContext(self::TRACE, $bad, '01', null, 'op.create');
                $this->fail("spanId '$bad' must throw");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testConstructorRejectsInvalidTraceFlags(): void
    {
        // Flags must be 2 hex chars and only the low bit may be set.
        foreach ([
            'ff',
            '03',
            '1',
            'zz',
        ] as $bad) {
            try {
                new CorrelationContext(self::TRACE, self::SPAN, $bad, null, 'op.create');
                $this->fail("flags '$bad' must throw");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testConstructorRejectsInvalidTraceState(): void
    {
        foreach ([
            '',
            str_repeat('x', 513),
            'UPPER=value',
            '=novalue',
            'bad key=value',
        ] as $bad) {
            try {
                new CorrelationContext(self::TRACE, self::SPAN, '01', $bad, 'op.create');
                $this->fail("traceState must throw");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testConstructorAcceptsMultiVendorTraceState(): void
    {
        $context = new CorrelationContext(
            self::TRACE,
            self::SPAN,
            '01',
            'vendor1=alpha,vendor2=beta,rojo=00f067aa0ba902b7',
            'op.create',
        );
        $this->assertSame('vendor1=alpha,vendor2=beta,rojo=00f067aa0ba902b7', $context->traceState);
    }

    public function testConstructorRejectsInvalidOperationAndIdempotencyTokens(): void
    {
        foreach ([
            '',
            str_repeat('a', 129),
            "has\nnewline",
            'has space',
        ] as $badOperation) {
            try {
                new CorrelationContext(self::TRACE, self::SPAN, '01', null, $badOperation);
                $this->fail('bad operationId must throw');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([
            '',
            str_repeat('b', 129),
        ] as $badKey) {
            try {
                new CorrelationContext(self::TRACE, self::SPAN, '01', null, 'op.create', $badKey);
                $this->fail('bad idempotencyKey must throw');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // A null idempotency key is valid; constructing it is the assertion.
        new CorrelationContext(self::TRACE, self::SPAN, '01', null, 'op.create', null);
        $this->addToAssertionCount(1);
    }

    public function testConstructorRejectsInvalidAttributes(): void
    {
        $tooMany = [];
        for ($i = 0; $i < 17; ++$i) {
            $tooMany["key$i"] = $i;
        }
        $badInputs = [
            $tooMany,
            ['' => 'v'],
            ['bad key' => 'v'],
            [str_repeat('k', 65) => 'v'],
            ['k' => ['array']],
            ['k' => str_repeat('v', 257)],
        ];
        foreach ($badInputs as $attributes) {
            $this->assertInvalidAttributesRejected($attributes);
        }
    }

    /** @param mixed $attributes */
    private function assertInvalidAttributesRejected(mixed $attributes): void
    {
        try {
            // Deliberately passing invalid attribute shapes to prove the
            // runtime validator rejects them; PHPStan cannot model that.
            /** @phpstan-ignore argument.type */
            new CorrelationContext(self::TRACE, self::SPAN, '01', null, 'op.create', null, $attributes);
            $this->fail('bad attributes must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testConstructorRejectsAggregateAttributeSize(): void
    {
        // 16 keys * (key ~64B + value ~200B) pushes past the 4096-byte cap.
        $attributes = [];
        for ($i = 0; $i < 16; ++$i) {
            $attributes['k' . str_pad((string) $i, 60, 'p')] = str_repeat('v', 200);
        }
        $this->expectException(InvalidArgumentException::class);
        new CorrelationContext(self::TRACE, self::SPAN, '01', null, 'op.create', null, $attributes);
    }

    public function testConstructorEnforcesTotalPropagationSizeLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CorrelationContext(self::TRACE, self::SPAN, '01', str_repeat('x', 512), 'op.create');
    }

    public function testRedactionOfSensitiveValues(): void
    {
        $context = new CorrelationContext(self::TRACE, self::SPAN, '01', null, 'op.create', null, [
            'http.authorization' => 'Bearer abc',
            'set-cookie' => 'sid=1',
            'x-csrf-token' => 'tok',
            'authorization.attempt' => 3,
            'request.body' => 'raw',
            'count' => 42,
            'enabled' => true,
            'nothing' => null,
        ]);
        $redacted = $context->redactedAttributes();
        $this->assertStringStartsWith('sha256:', (string) $redacted['http.authorization']);
        $this->assertStringStartsWith('sha256:', (string) $redacted['set-cookie'], 'string sensitive value is digested deterministically');
        $this->assertStringStartsWith('sha256:', (string) $redacted['x-csrf-token']);
        $this->assertSame('[REDACTED]', $redacted['authorization.attempt'], 'non-string sensitive value redacted to placeholder');
        $this->assertStringStartsWith('sha256:', (string) $redacted['request.body']);
        $this->assertSame(42, $redacted['count']);
        $this->assertTrue($redacted['enabled']);
        $this->assertNull($redacted['nothing']);
    }

    public function testExtractIsTolerantOnCaseAndVersion(): void
    {
        $upper = CorrelationPropagator::extract(
            '00-' . strtoupper(self::TRACE) . '-' . strtoupper(self::SPAN) . '-01',
            null,
            'op.create',
        );
        $this->assertNotNull($upper);
        $this->assertSame(self::TRACE, $upper->traceId, 'hex is lowercased');
        $this->assertSame('01', $upper->traceFlags);

        $badVersion = CorrelationPropagator::extract(
            '01-' . self::TRACE . '-' . self::SPAN . '-01',
            null,
            'op.create',
        );
        $this->assertNull($badVersion, 'non-00 version is rejected');

        $short = CorrelationPropagator::extract('00-aaaa', null, 'op.create');
        $this->assertNull($short);

        $oversized = CorrelationPropagator::extract('00-' . self::TRACE . '-' . self::SPAN . '-01-extra', null, 'op.create');
        $this->assertNull($oversized);

        $nullState = CorrelationPropagator::extract(
            '00-' . self::TRACE . '-' . self::SPAN . '-01',
            null,
            'op.create',
            'idem-12345',
        );
        $this->assertNotNull($nullState);
        $this->assertSame('idem-12345', $nullState->idempotencyKey);
    }

    public function testExtractReturnsNullWhenContextConstructionFails(): void
    {
        // All-zero trace id passes the regex but fails the constructor guard.
        $result = CorrelationPropagator::extract(
            '00-' . str_repeat('0', 32) . '-' . self::SPAN . '-01',
            null,
            'op.create',
        );
        $this->assertNull($result);
    }

    public function testCorrelationHeadersEnforcesConstructorBounds(): void
    {
        $valid = new CorrelationHeaders('00-' . self::TRACE . '-' . self::SPAN . '-01', null);
        $this->assertSame(55, $valid->encodedBytes());

        $withState = new CorrelationHeaders('00-' . self::TRACE . '-' . self::SPAN . '-01', 'vendor=value');
        $this->assertSame(55 + strlen('vendor=value'), $withState->encodedBytes());

        try {
            new CorrelationHeaders('short', null);
            $this->fail('short traceparent must throw');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        new CorrelationHeaders('00-' . self::TRACE . '-' . self::SPAN . '-01', str_repeat('x', 513));
    }

    public function testExplicitCarrierWithNullAndContext(): void
    {
        $empty = new Zef\Framework\Observability\ExplicitCorrelationContextCarrier(null);
        $this->assertNull($empty->correlationContext());
    }
}
