<?php

declare(strict_types=1);

namespace Zef\Framework\Job {
    interface JobInterface
    {
        public function handle(JobContext $context): mixed;
    }
}
