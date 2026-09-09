<?php

declare(strict_types=1);

namespace Zef\Framework\Job {
    interface JobQueueInterface
    {
        public function enqueue(JobEnvelope $job): void;
        public function dequeue(?int $nowUnixNano = null): ?JobEnvelope;
        public function size(): int;
    }
}
