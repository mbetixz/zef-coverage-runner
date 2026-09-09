<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    /**
     * Delivery semantics requested by the caller.
     *
     * AT_MOST_ONCE: never retry. RETRYABLE: may retry on indeterminate
     * outcomes without an idempotency guard. IDEMPOTENT: retries allowed only
     * with an idempotency key + operation fingerprint and a store guarantee.
     * INDETERMINATE: caller declares it cannot classify the operation.
     */
    enum DeliveryMode: string
    {
        case AT_MOST_ONCE = 'at_most_once';
        case RETRYABLE = 'retryable';
        case IDEMPOTENT = 'idempotent';
        case INDETERMINATE = 'indeterminate';
    }
}
