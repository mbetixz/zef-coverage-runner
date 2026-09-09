<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    /**
     * Safety classification of an operation.
     *
     * SIDE_EFFECTING operations must never be retried without an idempotency
     * guarantee; SIDE_EFFECT_FREE operations are safe to re-execute.
     */
    enum DeliverySafety: string
    {
        case SIDE_EFFECTING = 'side_effecting';
        case SIDE_EFFECT_FREE = 'side_effect_free';
    }
}
