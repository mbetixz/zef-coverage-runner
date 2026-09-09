<?php

declare(strict_types=1);

namespace Zef\Framework\Resource;

final readonly class AdmissionSnapshot
{
    public function __construct(
        public int $admitted,
        public int $rejected,
        public int $queued,
        public int $completed,
    ) {
    }
}
