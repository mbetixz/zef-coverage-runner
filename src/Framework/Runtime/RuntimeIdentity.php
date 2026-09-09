<?php

declare(strict_types=1);

namespace Zef\Framework\Runtime {
    final readonly class RuntimeIdentity
    {
        public function __construct(
            public string $instanceId,
            public string $workerId,
            public int $startedAtNs,
        ) {
            if ($instanceId === '' || $workerId === '') {
                throw new \InvalidArgumentException('Runtime identity values must not be empty.');
            }
            if ($startedAtNs < 0) {
                throw new \InvalidArgumentException('Runtime startedAtNs must be >= 0.');
            }
        }
    }

}
