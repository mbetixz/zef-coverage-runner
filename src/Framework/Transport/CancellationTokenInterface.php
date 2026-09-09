<?php

declare(strict_types=1);

namespace Zef\Framework\Transport {
    /**
     * Cooperative cancellation primitive for transport operations.
     *
     * isCancellationRequested() returns true once a cancellation has been
     * requested; implementations must be safe to poll repeatedly and should
     * never throw.
     */
    interface CancellationTokenInterface
    {
        public function isCancellationRequested(): bool;
    }
}
