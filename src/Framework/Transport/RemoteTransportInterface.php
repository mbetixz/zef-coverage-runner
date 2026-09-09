<?php

declare(strict_types=1);

namespace Zef\Framework\Transport {
    /**
     * Port for remote transport adapters (HTTP, RPC, broker clients).
     *
     * send() performs one attempt of $request under $context and returns the
     * outcome plus any response payload. Implementations must honour the
     * cancellation token and deadline of $context and must classify every
     * outcome via TransportOutcome (including INDETERMINATE when the attempt
     * produced no definitive answer).
     */
    interface RemoteTransportInterface
    {
        public function send(RemoteRequest $request, TransportContext $context): RemoteTransportResult;
    }
}
