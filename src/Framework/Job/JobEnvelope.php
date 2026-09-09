<?php

declare(strict_types=1);

namespace Zef\Framework\Job {
    /**
     * Immutable job envelope (scheduling unit).
     *
     * Bounds (enforced in the constructor): jobId is 8..128 chars of
     * [A-Za-z0-9._:-]; jobType is 1..255 chars of [A-Za-z0-9._:\/-]; attempt is
     * >= 1 (1 = first attempt); correlationId, when set, follows the jobId
     * pattern; traceParent, when set, is a W3C traceparent. Headers are limited
     * to 32 entries with names of 1..128 chars of [A-Za-z0-9._-] and values
     * <= 2048 bytes. availableAtUnixNano is the Unix-nanosecond time from which
     * the job may run; priority is an ordering hint (lower = sooner).
     */
    final readonly class JobEnvelope
    {
        /**
         * @param array<string,string> $headers header map (at most 32 entries).
         *
         * @throws \InvalidArgumentException when any field violates the bounds
         *         documented above.
         */
        public function __construct(
            public string $jobId,
            public string $jobType,
            public mixed $payload,
            public int $availableAtUnixNano,
            public int $priority = 0,
            public int $attempt = 1,
            public ?string $correlationId = null,
            public ?string $traceParent = null,
            public array $headers = [],
        ) {
            if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $jobId) !== 1) {
                throw new \InvalidArgumentException('Invalid job ID.');
            }
            if (preg_match('/^[A-Za-z0-9._:\/-]{1,255}$/', $jobType) !== 1) {
                throw new \InvalidArgumentException('Invalid job type.');
            }
            if ($attempt < 1) {
                throw new \InvalidArgumentException('Job attempt must be positive.');
            }
            if ($correlationId !== null && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $correlationId) !== 1) {
                throw new \InvalidArgumentException('Invalid job correlation ID.');
            }
            if ($traceParent !== null && preg_match('/^[0-9a-f]{2}-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}(?:-[^\s]{1,512})?$/i', $traceParent) !== 1) {
                throw new \InvalidArgumentException('Invalid W3C traceparent.');
            }
            if (count($headers) > 32) {
                throw new \InvalidArgumentException('Job header count exceeds the limit.');
            }
            foreach ($headers as $name => $value) {
                if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name) !== 1) {
                    throw new \InvalidArgumentException('Invalid job header name.');
                }
                if (strlen($value) > 2048) {
                    throw new \InvalidArgumentException('Job header value exceeds the limit.');
                }
            }
        }
        /**
         * New envelope for the next attempt: attempt is incremented by 1 and
         * availableAtUnixNano is advanced by $delayMs from the current time.
         *
         * @throws \InvalidArgumentException when $delayMs is negative.
         */
        public function nextAttempt(int $delayMs): self
        {
            if ($delayMs < 0) {
                throw new \InvalidArgumentException('Job retry delay cannot be negative.');
            }
            return new self($this->jobId, $this->jobType, $this->payload, (int) (microtime(true) * 1_000_000_000) + ($delayMs * 1_000_000), $this->priority, $this->attempt + 1, $this->correlationId, $this->traceParent, $this->headers);
        }
    }
}
