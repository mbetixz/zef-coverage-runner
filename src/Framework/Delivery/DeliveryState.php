<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    /**
     * States of the delivery lifecycle state machine. Terminal states are
     * DEFINITIVE_SUCCESS, DEFINITIVE_FAILURE and TERMINAL_INDETERMINATE;
     * all transitions are enforced by DeliveryStateMachine.
     */
    enum DeliveryState: string
    {
        case PREPARED = 'prepared';
        case ATTEMPTING = 'attempting';
        case DEFINITIVE_SUCCESS = 'definitive_success';
        case DEFINITIVE_FAILURE = 'definitive_failure';
        case INDETERMINATE = 'indeterminate';
        case TERMINAL_INDETERMINATE = 'terminal_indeterminate';
        case RETRY_SCHEDULED = 'retry_scheduled';
        case RECONCILING = 'reconciling';
    }
}
