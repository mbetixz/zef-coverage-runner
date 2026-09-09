<?php

declare(strict_types=1);

namespace Zef\Framework\Job {
    final class InMemoryJobQueue implements JobQueueInterface
    {
        /** @var \SplPriorityQueue<mixed,mixed> */
        private \SplPriorityQueue $queue;
        private int $sequence = 0;

        public function __construct(private readonly int $maxSize = 10_000)
        {
            if ($maxSize < 1) {
                throw new \InvalidArgumentException('Job queue capacity must be positive.');
            }
            $this->queue = new class () extends \SplPriorityQueue {
                #[\Override]
                public function compare(mixed $priority1, mixed $priority2): int
                {
                    /** @var array{0:int,1:int,2:int} $priority1 */
                    /** @var array{0:int,1:int,2:int} $priority2 */
                    for ($i = 0; $i < 3; ++$i) {
                        if ($priority1[$i] === $priority2[$i]) {
                            continue;
                        }
                        return $priority1[$i] <=> $priority2[$i];
                    }
                    return 0;
                }
            };
            $this->queue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        }

        #[\Override]
        public function enqueue(JobEnvelope $job): void
        {
            if ($this->queue->count() >= $this->maxSize) {
                throw new \OverflowException('Job queue capacity exceeded.');
            }
            $priority = [-$job->availableAtUnixNano, $job->priority, -$this->sequence++];
            $this->queue->insert($job, $priority);
        }

        #[\Override]
        public function dequeue(?int $nowUnixNano = null): ?JobEnvelope
        {
            if ($this->queue->isEmpty()) {
                return null;
            }
            $this->queue->top();
            $item = $this->queue->current();
            if (!is_array($item) || !isset($item['data']) || !($item['data'] instanceof JobEnvelope)) {
                throw new \LogicException('Invalid internal job queue state.');
            }
            if ($item['data']->availableAtUnixNano > ($nowUnixNano ?? (int) (microtime(true) * 1_000_000_000))) {
                return null;
            }
            $extracted = $this->queue->extract();
            if (!is_array($extracted) || !isset($extracted['data']) || !($extracted['data'] instanceof JobEnvelope)) {
                throw new \LogicException('Invalid extracted job queue item.');
            }
            return $extracted['data'];
        }

        #[\Override]
        public function size(): int
        {
            return $this->queue->count();
        }
    }
}
