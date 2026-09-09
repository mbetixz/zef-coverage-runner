<?php

declare(strict_types=1);

namespace Zef\Framework\Transport {
    // Compatibility aggregate retained for explicit legacy requires.
    // Public declarations moved to per-type files (D2 structural decomposition):
    // interface RemoteTransportInterface, enum TransportOutcome,
    // final readonly class RemoteRequest, final readonly class RemoteTransportResult,
    // class TransportContext, class CancellationTokenInterface (interface),
    // class NeverCancelledToken. Bounds retained per-type: payload bound
    // 1_048_576 bytes, metadata count bound 32, isCancelled cancellation
    // boundary, INDETERMINATE outcome first-class. Core transport stays
    // vendor/runtime-neutral with no global mutable state and no network
    // client primitives (see per-type files).
}

namespace {
    require_once __DIR__ . '/TransportOutcome.php';
    require_once __DIR__ . '/CancellationTokenInterface.php';
    require_once __DIR__ . '/NeverCancelledToken.php';
    require_once __DIR__ . '/RemoteRequest.php';
    require_once __DIR__ . '/TransportContext.php';
    require_once __DIR__ . '/RemoteTransportResult.php';
    require_once __DIR__ . '/RemoteTransportInterface.php';
}
