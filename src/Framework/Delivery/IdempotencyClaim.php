<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    /**
     * Idempotency claim outcome for an idempotency-key claim.
     *
     * NEW: first claim, key recorded; DUPLICATE: same key + fingerprint,
     * caller may replay the stored completed result; CONFLICT: same key with a
     * different fingerprint (must not proceed).
     */
    enum IdempotencyClaim: string
    {
        case NEW = 'new';
        case DUPLICATE = 'duplicate';
        case CONFLICT = 'conflict';
    }
}
