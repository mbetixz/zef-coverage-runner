<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Observability\CorrelationContext;
use Zef\Framework\Observability\CorrelationHeaders;
use Zef\Framework\Observability\CorrelationPropagator;
use Zef\Framework\Observability\ExplicitCorrelationContextCarrier;

final class G4_8CorrelationContextTest extends TestCase
{
    private const TRACE = '11111111111111111111111111111111';
    private const SPAN = '2222222222222222';

    public function testValidW3cContextAndInjection(): void
    {
        $context = CorrelationPropagator::extract(
            '00-'.self::TRACE.'-'.self::SPAN.'-01',
            'vendor=value',
            'orders.create',
            'idem-001',
            ['operation.class' => 'order', 'status' => 200],
        );
        self::assertInstanceOf(CorrelationContext::class, $context);
        self::assertSame('00-'.self::TRACE.'-'.self::SPAN.'-01', $context->traceParent());
        $headers = CorrelationPropagator::inject($context);
        self::assertInstanceOf(CorrelationHeaders::class, $headers);
        self::assertSame(55, $headers->encodedBytes() - strlen('vendor=value'));
    }

    public function testMalformedAndOversizedPropagationIsRejected(): void
    {
        self::assertNull(CorrelationPropagator::extract('invalid', null, 'orders.create'));
        self::assertNull(CorrelationPropagator::extract('00-'.str_repeat('a', 32).'-'.str_repeat('b', 16).'-01', str_repeat('x', 513), 'orders.create'));
        self::assertNull(CorrelationPropagator::extract('00-'.str_repeat('0', 32).'-'.self::SPAN.'-01', null, 'orders.create'));
    }

    public function testBoundsAreEnforced(): void
    {
        self::expectException(InvalidArgumentException::class);
        new CorrelationContext(
            self::TRACE,
            self::SPAN,
            '01',
            null,
            'orders.create',
            null,
            array_fill_keys(array_map(static fn(int $i): string => 'k'.$i, range(1, 17)), 'v'),
        );
    }

    public function testSensitiveAttributesAreRedactedDeterministically(): void
    {
        $context = new CorrelationContext(self::TRACE, self::SPAN, '01', null, 'orders.create', 'secret-idem', [
            'authorization.token' => 'secret-token',
            'operation.class' => 'order',
        ]);
        $redacted = $context->redactedAttributes();
        self::assertStringStartsWith('sha256:', (string) $redacted['authorization.token']);
        self::assertSame('order', $redacted['operation.class']);
    }

    public function testIdempotencyKeyIsNotInjectedIntoW3cHeaders(): void
    {
        $context = new CorrelationContext(self::TRACE, self::SPAN, '01', null, 'orders.create', 'idem-secret');
        $headers = CorrelationPropagator::inject($context);
        self::assertInstanceOf(CorrelationHeaders::class, $headers);
        self::assertStringNotContainsString('idem-secret', $headers->traceParent);
    }

    public function testExplicitCarrierAvoidsGlobalState(): void
    {
        $context = new CorrelationContext(self::TRACE, self::SPAN, '01', null, 'orders.create');
        $carrier = new ExplicitCorrelationContextCarrier($context);
        self::assertSame($context, $carrier->correlationContext());
    }

    public function testDisabledPathUsesNullWithoutContextAllocation(): void
    {
        self::assertSame(null, CorrelationPropagator::disabled());
        self::assertNull(CorrelationPropagator::inject(null));
    }
}
