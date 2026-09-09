<?php

declare(strict_types=1);

namespace Zef\Framework\Job {
    interface JobHandlerInterface
    {
        public function __invoke(JobEnvelope $job, JobContext $context): mixed;
    }
}
