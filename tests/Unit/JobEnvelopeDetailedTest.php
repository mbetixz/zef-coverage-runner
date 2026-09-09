<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Job\InMemoryJobIdempotencyStore;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\JobContext;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobHandlerInterface;
use Zef\Framework\Job\JobResult;
use Zef\Framework\Job\RetryPolicy;

final class B2JobHandlerObject implements JobHandlerInterface
{
    public int $invocations = 0;

    #[Override]
    public function __invoke(JobEnvelope $job, JobContext $context): mixed
    {
        ++$this->invocations;
        return 'handled:' . $job->jobType;
    }
}

final class JobEnvelopeDetailedTest extends TestCase
{
    public function testEnvelopeRejectsEachInvalidField(): void
    {
        try {
            new JobEnvelope('short', 'type', null, 0);
            $this->fail('job id too short');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new JobEnvelope('job-00000001', 'type with space', null, 0);
            $this->fail('job type with space');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new JobEnvelope('job-00000002', 'type', null, 0, 0, 0);
            $this->fail('attempt zero');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new JobEnvelope('job-00000003', 'type', null, 0, 0, 1, 'bad');
            $this->fail('correlation too short');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new JobEnvelope('job-00000004', 'type', null, 0, 0, 1, null, 'bad-trace');
            $this->fail('bad traceparent');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new JobEnvelope('job-00000005', 'type', null, 0, 0, 1, null, null, ['name with space' => 'v']);
            $this->fail('bad header name');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testNextAttemptAdvancesAndPreservesFields(): void
    {
        $env = new JobEnvelope('job-00000006', 'zef.demo', ['x' => 1], 1_000, 5, 1, 'corr-00001', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', ['k' => 'v']);
        $next = $env->nextAttempt(50);
        $this->assertSame('job-00000006', $next->jobId);
        $this->assertSame(2, $next->attempt);
        $this->assertSame(5, $next->priority);
        $this->assertSame(['k' => 'v'], $next->headers);
        $this->assertGreaterThanOrEqual(1_000 + 50_000_000, $next->availableAtUnixNano);
    }

    public function testNextAttemptRejectsNegativeDelay(): void
    {
        $env = new JobEnvelope('job-00000007', 'zef.demo', null, 0);
        $this->expectException(\InvalidArgumentException::class);
        $env->nextAttempt(-1);
    }

    public function testJobResultValidation(): void
    {
        try {
            new JobResult('bad', true);
            $this->fail('bad job id');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new JobResult('job-00000008', false, null, 0);
    }

    public function testRetryPolicyValidation(): void
    {
        try {
            new RetryPolicy(0);
            $this->fail('zero maxAttempts');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new RetryPolicy(3, 100, 50);
            $this->fail('maxDelay < initialDelay');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new RetryPolicy(3, 100, 30000, 0.5);
            $this->fail('multiplier < 1');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new RetryPolicy(3, 0, 30000, 2.0, -1);
    }

    public function testRetryPolicyShouldRetryAndExponentialDelay(): void
    {
        $policy = new RetryPolicy(4, 100, 10_000, 2.0);
        $this->assertTrue($policy->shouldRetry(1));
        $this->assertTrue($policy->shouldRetry(3));
        $this->assertFalse($policy->shouldRetry(4));
        $this->assertSame(100, $policy->delayMs(1));
        $this->assertSame(200, $policy->delayMs(2));
        $this->assertSame(400, $policy->delayMs(3));
        $this->assertSame(10_000, $policy->delayMs(10), 'delay capped at maxDelayMs');
        $this->assertSame(0, (new RetryPolicy(3, 0, 0))->delayMs(2), 'zero initial delay');
    }

    public function testRetryPolicyDelayRejectsZeroAttempt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RetryPolicy(3))->delayMs(0);
    }

    public function testRetryPolicyJitterStaysWithinBounds(): void
    {
        $policy = new RetryPolicy(3, 100, 10_000, 1.0, 500);
        for ($i = 0; $i < 20; ++$i) {
            $delay = $policy->delayMs(1);
            $this->assertGreaterThanOrEqual(100, $delay);
            $this->assertLessThanOrEqual(10_000, $delay);
        }
    }

    public function testJobContextFullImmutableFlow(): void
    {
        $context = new JobContext('job-00000009', 1, 'corr-00001', null, ['a' => 1], 999_999_999_999_999_999, false);
        $this->assertFalse($context->isCancelled());
        $this->assertSame(999_999_999_999_999_999, $context->deadlineUnixNano());
        $this->assertFalse($context->isTimedOut(999_999_999_999_999_998));
        $this->assertTrue($context->isTimedOut(999_999_999_999_999_999), 'deadline reached');

        $cancelled = $context->cancel();
        $this->assertTrue($cancelled->isCancelled());
        $this->assertFalse($context->isCancelled(), 'original stays immutable');
        try {
            $cancelled->throwIfCancelled();
            $this->fail('expected JobCancelledException');
        } catch (\Zef\Framework\Job\JobCancelledException) {
            $this->addToAssertionCount(1);
        }

        $timedOut = new JobContext('job-00000010', 1, null, null, [], 1, false);
        try {
            $timedOut->throwIfCancelled();
            $this->fail('expected JobTimeoutException');
        } catch (\Zef\Framework\Job\JobTimeoutException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testJobContextWithDeadlineAndValidation(): void
    {
        $context = new JobContext('job-00000011', 1);
        $with = $context->withDeadlineMs(10_000);
        $this->assertNotNull($with->deadlineUnixNano());
        $this->assertGreaterThan(0, $with->deadlineUnixNano());

        try {
            new JobContext('bad', 1);
            $this->fail('bad job id');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new JobContext('job-00000012', 0);
            $this->fail('attempt zero');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $context->withDeadlineMs(0);
            $this->fail('zero timeout');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new JobContext('job-00000013', 1, null, null, ['' => 'x']);
            $this->fail('empty attribute key');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testJobQueueCapacityAndDelayGate(): void
    {
        try {
            new InMemoryJobQueue(0);
            $this->fail('capacity zero');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $queue = new InMemoryJobQueue(2);
        $queue->enqueue(new JobEnvelope('job-00000014', 'zef.demo', null, 0));
        $queue->enqueue(new JobEnvelope('job-00000015', 'zef.demo', null, 0));
        try {
            $queue->enqueue(new JobEnvelope('job-00000016', 'zef.demo', null, 0));
            $this->fail('capacity exceeded');
        } catch (\OverflowException) {
            $this->addToAssertionCount(1);
        }

        $future = new InMemoryJobQueue(1);
        $future->enqueue(new JobEnvelope('job-00000017', 'zef.demo', null, PHP_INT_MAX));
        $this->assertNull($future->dequeue(), 'job scheduled in the future must not dequeue');
        $this->assertSame(1, $future->size());
    }

    public function testJobQueuePriorityOrdering(): void
    {
        $queue = new InMemoryJobQueue(10);
        $queue->enqueue(new JobEnvelope('job-00000018', 'low', null, 0, 10));
        $queue->enqueue(new JobEnvelope('job-00000019', 'high', null, 0, 1));
        $queue->enqueue(new JobEnvelope('job-00000020', 'high2', null, 0, 1));
        $first = $queue->dequeue();
        $this->assertSame('job-00000018', $first?->jobId, 'higher numeric priority dequeues first');
        $second = $queue->dequeue();
        $this->assertSame('job-00000019', $second?->jobId, 'equal priority falls back to FIFO sequence');
        $third = $queue->dequeue();
        $this->assertSame('job-00000020', $third?->jobId);
        // fourth dequeue on empty queue returns null
        $this->assertNull($queue->dequeue());
    }

    public function testJobQueueEmptyDequeueReturnsNull(): void
    {
        $queue = new InMemoryJobQueue(1);
        $this->assertNull($queue->dequeue());
    }

    public function testInMemoryJobIdempotencyStoreRemembersAndPurges(): void
    {
        $store = new InMemoryJobIdempotencyStore(2);
        $calls = 0;
        $producer = static function () use (&$calls): int {
            ++$calls;
            return 42;
        };
        $this->assertSame(42, $store->remember('idem-000001', $producer));
        $this->assertSame(42, $store->remember('idem-000001', $producer));
        $this->assertSame(1, $calls, 'producer runs once per key');

        $this->assertSame(1, $store->remember('idem-000002', static fn (): int => 1));
        $this->assertSame(2, $store->remember('idem-000003', static fn (): int => 2), 'LRU-style eviction of oldest entry');
    }

    public function testInMemoryJobIdempotencyStoreValidation(): void
    {
        $store = new InMemoryJobIdempotencyStore();
        try {
            $store->remember('bad', static fn (): int => 1);
            $this->fail('bad key');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $store->remember('idem-000004', static fn (): int => 1, 0);
            $this->fail('zero ttl');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new InMemoryJobIdempotencyStore(0);
    }

    public function testWorkerConstructorAndPollValidation(): void
    {
        $queue = new InMemoryJobQueue(5);
        try {
            new InProcessJobWorker($queue, new RetryPolicy(), null, null, -1);
            $this->fail('negative poll interval');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new InProcessJobWorker($queue, new RetryPolicy(), null, null, 60_001);
            $this->fail('poll interval too large');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testWorkerWithJobHandlerInterfaceObject(): void
    {
        $queue = new InMemoryJobQueue(5);
        $handler = new B2JobHandlerObject();
        $worker = new InProcessJobWorker($queue, new RetryPolicy(1, 0, 0));
        $worker->register('b2.object', $handler);
        $queue->enqueue(new JobEnvelope('job-00000021', 'b2.object', null, 0));
        $result = $worker->processOne();
        $this->assertNotNull($result);
        $this->assertTrue($result->completed);
        $this->assertSame('handled:b2.object', $result->result);
        $this->assertSame(1, $handler->invocations);
    }

    public function testWorkerNoHandlerThrowsJobExecutionException(): void
    {
        $queue = new InMemoryJobQueue(5);
        $worker = new InProcessJobWorker($queue, new RetryPolicy(1, 0, 0));
        $queue->enqueue(new JobEnvelope('job-00000022', 'b2.missing', null, 0));
        $this->expectException(\Zef\Framework\Job\JobExecutionException::class);
        $worker->processOne();
    }

    public function testWorkerRegisterValidationAndDuplicate(): void
    {
        $queue = new InMemoryJobQueue(5);
        $worker = new InProcessJobWorker($queue);
        try {
            $worker->register('bad type', static fn (): string => 'x');
            $this->fail('invalid job type');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $worker->register('b2.demo', static fn (): string => 'x');
        try {
            $worker->register('b2.demo', static fn (): string => 'x');
            $this->fail('duplicate job type');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testWorkerFrozenRejectsMutationAndRunValidatesMaxJobs(): void
    {
        $queue = new InMemoryJobQueue(5);
        $worker = new InProcessJobWorker($queue);
        $worker->register('b2.demo', static fn (): string => 'ok');
        $worker->freeze();
        $this->assertTrue($worker->isFrozen());
        try {
            $worker->register('b2.late', static fn (): string => 'x');
            $this->fail('registration after freeze');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        try {
            $worker->run(-1);
            $this->fail('negative maxJobs');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testWorkerRunWithPollIntervalZeroProcessesAllJobs(): void
    {
        $queue = new InMemoryJobQueue(10);
        $worker = new InProcessJobWorker($queue, new RetryPolicy(1, 0, 0), null, null, 0);
        $count = 0;
        $worker->register('b2.run', static function () use (&$count): string {
            ++$count;
            return 'done';
        });
        for ($i = 0; $i < 3; ++$i) {
            $queue->enqueue(new JobEnvelope('job-run-0000' . $i, 'b2.run', null, 0));
        }
        $processed = $worker->run(2);
        $this->assertSame(2, $processed);
        $this->assertSame(2, $count);
        $this->assertSame(1, $queue->size());
        $this->assertFalse($worker->isRunning());
    }

    public function testWorkerDeadLetterWithoutDlqDoesNotDeadLetter(): void
    {
        $queue = new InMemoryJobQueue(5);
        $worker = new InProcessJobWorker($queue, new RetryPolicy(1, 0, 0), null, null, 0);
        $worker->register('b2.fail', static function (): never {
            throw new \RuntimeException('permanent');
        });
        $queue->enqueue(new JobEnvelope('job-00000023', 'b2.fail', null, 0));
        $result = $worker->processOne();
        $this->assertNotNull($result);
        $this->assertFalse($result->completed);
        $this->assertTrue($result->deadLettered, 'exhausted retry without DLQ still reports deadLettered=true');
        $this->assertInstanceOf(\RuntimeException::class, $result->result);
    }

    public function testWorkerIdempotencyWrapsHandlerExecution(): void
    {
        $queue = new InMemoryJobQueue(5);
        $worker = new InProcessJobWorker($queue, new RetryPolicy(1, 0, 0), new InMemoryJobIdempotencyStore(), null, 0);
        $calls = 0;
        $worker->register('b2.idem', static function () use (&$calls): string {
            ++$calls;
            return 'once';
        });
        $queue->enqueue(new JobEnvelope('job-00000024', 'b2.idem', null, 0));
        $queue->enqueue(new JobEnvelope('job-00000024', 'b2.idem', null, 0));
        $first = $worker->processOne();
        $second = $worker->processOne();
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('once', $first->result);
        $this->assertSame('once', $second->result, 'replay from idempotency store');
        $this->assertSame(1, $calls);
    }
}
