<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Runtime\BlockingSleeper;
use Zef\Framework\Runtime\InMemoryWorker;
use Zef\Framework\Runtime\RuntimeExtensionContext;
use Zef\Framework\Runtime\RuntimeExtensionState;
use Zef\Framework\Runtime\RuntimeIdentity;

final class RuntimePrimitivesTest extends TestCase
{
    public function testRuntimeIdentityExposesValues(): void
    {
        $identity = new RuntimeIdentity('instance-9', 'worker-2', 123);

        $this->assertSame('instance-9', $identity->instanceId);
        $this->assertSame('worker-2', $identity->workerId);
        $this->assertSame(123, $identity->startedAtNs);
    }

    public function testRuntimeIdentityRejectsEmptyInstanceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RuntimeIdentity('', 'worker-2', 123);
    }

    public function testRuntimeIdentityRejectsEmptyWorkerId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RuntimeIdentity('instance-9', '', 123);
    }

    public function testRuntimeIdentityRejectsNegativeStartTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RuntimeIdentity('instance-9', 'worker-2', -1);
    }

    public function testExtensionStateValues(): void
    {
        $this->assertSame('starting', RuntimeExtensionState::STARTING->value);
        $this->assertSame('ready', RuntimeExtensionState::READY->value);
        $this->assertSame('draining', RuntimeExtensionState::DRAINING->value);
        $this->assertSame('stopped', RuntimeExtensionState::STOPPED->value);
    }

    public function testRuntimeExtensionContextAcceptsScalarAttributes(): void
    {
        $identity = new RuntimeIdentity('i', 'w', 0);
        $context = new RuntimeExtensionContext($identity, RuntimeExtensionState::READY, ['ttl' => 30, 'debug' => null]);

        $this->assertSame($identity, $context->identity);
        $this->assertSame(RuntimeExtensionState::READY, $context->state);
        $this->assertSame(['ttl' => 30, 'debug' => null], $context->attributes);
    }

    public function testRuntimeExtensionContextRejectsEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RuntimeExtensionContext(new RuntimeIdentity('i', 'w', 0), RuntimeExtensionState::READY, ['' => 1]);
    }

    public function testRuntimeExtensionContextRejectsNonScalarAttribute(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RuntimeExtensionContext(new RuntimeIdentity('i', 'w', 0), RuntimeExtensionState::READY, ['list' => [1]]);
    }

    public function testBlockingSleeperRejectsNegativeDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BlockingSleeper::sleepMilliseconds(-1);
    }

    public function testBlockingSleeperZeroIsImmediate(): void
    {
        BlockingSleeper::sleepMilliseconds(0);
        $this->addToAssertionCount(1);
    }

    public function testBlockingSleeperShortDurationRuns(): void
    {
        BlockingSleeper::sleepMilliseconds(1);
        $this->addToAssertionCount(1);
    }

    public function testInMemoryWorkerDrainsQueuedRequests(): void
    {
        $requests = [
            new ServerRequest('GET', new Uri('http://example.com/a')),
            new ServerRequest('GET', new Uri('http://example.com/b')),
        ];
        $worker = new InMemoryWorker($requests);

        $this->assertTrue($worker->isRunning());
        $first = $worker->waitRequest();
        $this->assertNotNull($first);
        $this->assertSame('/a', $first->getRequestTarget());
        $second = $worker->waitRequest();
        $this->assertNotNull($second);
        $this->assertSame('/b', $second->getRequestTarget());
        $this->assertNull($worker->waitRequest(), 'exhausted worker returns null');
    }

    public function testInMemoryWorkerCollectsResponses(): void
    {
        $worker = new InMemoryWorker([]);
        $factory = new Zef\Framework\Http\Psr17Factory();
        $response = $factory->createResponse(200);

        $worker->respond($response);
        $worker->error('boom'); // no-op, must not throw

        $this->assertSame([$response], $worker->responses());
    }

    public function testInMemoryWorkerStopFlag(): void
    {
        $worker = new InMemoryWorker([]);
        $worker->stop();

        $this->assertFalse($worker->isRunning());
    }
}
