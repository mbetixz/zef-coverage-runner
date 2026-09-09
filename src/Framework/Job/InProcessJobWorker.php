<?php

declare(strict_types=1);

namespace Zef\Framework\Job {
    /**
     * Single-process job worker: registers handlers, runs a queue loop or a
     * single job, and produces JobResult outcomes.
     *
     * Handlers are keyed by jobType (1..255 chars of [A-Za-z0-9._:\/-]) and
     * may be a bare callable, a JobHandlerInterface, or a JobInterface.
     * register()/use() are only allowed before freeze(); run() freezes the
     * worker and processes up to maxJobs (0 = unlimited) until the optional
     * stopSignal callback returns true; pollIntervalMs (0..60_000) paces idle
     * dequeues via BlockingSleeper. When an idempotency store is present,
     * execute() wraps the handler run in idempotency->remember() keyed by
     * sha256(jobType|jobId). On failure the retry policy decides between a
     * requeue at nextAttempt(delayMs) and a dead-lettered JobResult.
     */
    final class InProcessJobWorker
    {
        /** @var array<string,callable> */ private array $handlers = [];
        /** @var list<JobMiddlewareInterface> */ private array $middleware = [];
        private bool $frozen = false;
        private bool $running = false;
        public function __construct(
            private readonly JobQueueInterface $queue,
            private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
            private readonly ?JobIdempotencyStoreInterface $idempotency = null,
            private readonly ?JobQueueInterface $deadLetterQueue = null,
            private readonly int $pollIntervalMs = 10,
        ) {
            if ($pollIntervalMs < 0 || $pollIntervalMs > 60_000) {
                throw new \InvalidArgumentException('Invalid job poll interval.');
            }
        }
        public function register(string $jobType, callable|JobHandlerInterface|JobInterface $handler): void
        {
            $this->assertMutable();
            if (preg_match('/^[A-Za-z0-9._:\/-]{1,255}$/', $jobType) !== 1) {
                throw new \InvalidArgumentException('Invalid job type.');
            }
            if (isset($this->handlers[$jobType])) {
                throw new \LogicException("Job handler already registered for '{$jobType}'.");
            }
            if ($handler instanceof JobInterface) {
                $this->handlers[$jobType] = static fn (JobEnvelope $_job, JobContext $context): mixed => $handler->handle($context);
            } elseif ($handler instanceof JobHandlerInterface) {
                $this->handlers[$jobType] = $handler(...);
            } else {
                $this->handlers[$jobType] = $handler;
            }
        }
        public function use(JobMiddlewareInterface $middleware): void
        {
            $this->assertMutable();
            $this->middleware[] = $middleware;
        }
        public function freeze(): void
        {
            $this->frozen = true;
        }
        public function isFrozen(): bool
        {
            return $this->frozen;
        }
        public function isRunning(): bool
        {
            return $this->running;
        }
        public function run(int $maxJobs = 0, ?callable $stopSignal = null): int
        {
            if ($maxJobs < 0) {
                throw new \InvalidArgumentException('maxJobs cannot be negative.');
            }
            if (!$this->frozen) {
                $this->freeze();
            } $processed = 0;
            $this->running = true;
            try {
                while (($maxJobs === 0 || $processed < $maxJobs) && !($stopSignal !== null && $stopSignal())) {
                    $job = $this->queue->dequeue();
                    if ($job === null) {
                        if ($this->pollIntervalMs > 0) {
                            \Zef\Framework\Runtime\BlockingSleeper::sleepMilliseconds($this->pollIntervalMs);
                        } continue;
                    } $this->execute($job);
                    ++$processed;
                }
            } finally {
                $this->running = false;
            }
            return $processed;
        }
        public function processOne(): ?JobResult
        {
            if (!$this->frozen) {
                $this->freeze();
            } $job = $this->queue->dequeue();
            return $job === null ? null : $this->execute($job);
        }
        private function execute(JobEnvelope $job): JobResult
        {
            $handler = $this->handlers[$job->jobType] ?? null;
            if (!is_callable($handler)) {
                throw new JobExecutionException("No handler registered for '{$job->jobType}'.");
            }
            $context = new JobContext($job->jobId, $job->attempt, $job->correlationId, $job->traceParent);
            $run = function () use ($handler, $job, $context): mixed {
                $next = \Closure::fromCallable($handler);
                for ($i = count($this->middleware) - 1;$i >= 0;--$i) {
                    $middleware = $this->middleware[$i];
                    $next = static fn (JobEnvelope $message, JobContext $ctx): mixed => $middleware->process($message, $ctx, $next);
                } $context->throwIfCancelled();
                return $next($job, $context);
            };
            try {
                $result = $this->idempotency !== null ? $this->idempotency->remember(hash('sha256', $job->jobType . '|' . $job->jobId), $run) : $run();
                return new JobResult($job->jobId, true, $result, $job->attempt);
            } catch (\Throwable $e) {
                if (!$this->retryPolicy->shouldRetry($job->attempt)) {
                    if ($this->deadLetterQueue !== null) {
                        $this->deadLetterQueue->enqueue($job);
                    } return new JobResult($job->jobId, false, $e, $job->attempt, true);
                } $this->queue->enqueue($job->nextAttempt($this->retryPolicy->delayMs($job->attempt)));
                return new JobResult($job->jobId, false, $e, $job->attempt, false);
            }
        }
        private function assertMutable(): void
        {
            if ($this->frozen) {
                throw new \LogicException('Job worker is frozen.');
            }
        }
    }
}
