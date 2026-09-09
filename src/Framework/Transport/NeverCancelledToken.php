<?php

declare(strict_types=1);

namespace Zef\Framework\Transport {
    /**
     * Cancellation token that never reports cancellation.
     *
     * Useful as the default for transports that do not support cooperative
     * cancellation: isCancellationRequested() is constant false.
     */
    final class NeverCancelledToken implements CancellationTokenInterface
    {
        #[\Override]
        public function isCancellationRequested(): bool
        {
            return false;
        }
    }
}
