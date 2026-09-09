<?php

declare(strict_types=1);

namespace Zef\Framework\Job {
    /**
     * Immutable outcome of a single job execution.
     *
     * jobId must match the executed envelope's id (8..128 chars of
     * [A-Za-z0-9._:-]); completed reports whether the handler ran to
     * completion (true) or failed with a throwable (false); result carries the
     * handler's return value when completed, or the caught \Throwable when
     * not; attempt is the 1-based attempt number that produced this result;
     * deadLettered is true only when the failure exhausted the retry policy
     * and the envelope was routed to the dead-letter queue.
     *
     * @throws \InvalidArgumentException when jobId is malformed or attempt < 1.
     */
    final readonly class JobResult
    {
        /**
         * @param mixed $result handler return value on success, or the caught \Throwable on failure
         * @param bool $deadLettered true only when retries were exhausted and the job was dead-lettered
         */
        public function __construct(
            public string $jobId,
            public bool $completed,
            public mixed $result = null,
            public int $attempt = 1,
            public bool $deadLettered = false,
        ) {
            if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $jobId) !== 1) {
                throw new \InvalidArgumentException('Invalid result job ID.');
            }
            if ($attempt < 1) {
                throw new \InvalidArgumentException('Job result attempt must be positive.');
            }
        }
    }
}
