<?php

declare(strict_types=1);

namespace Zef\Framework\Job {
    interface JobMiddlewareInterface
    {
        public function process(JobEnvelope $job, JobContext $context, \Closure $next): mixed;
    }
}
