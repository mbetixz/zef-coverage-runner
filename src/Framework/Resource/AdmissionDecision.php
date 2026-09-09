<?php

declare(strict_types=1);

namespace Zef\Framework\Resource;

final readonly class AdmissionDecision
{
    public function __construct(
        public bool $admitted,
        public string $reason,
        public int $inFlight,
        public int $queued,
    ) {
        if ($reason === '' || strlen($reason) > 64) {
            throw new \InvalidArgumentException('Admission reason is invalid.');
        }
    }
}
