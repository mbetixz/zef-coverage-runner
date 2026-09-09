<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    /**
     * How certain the framework is that an operation executed remotely.
     *
     * DEFINITELY_NOT_EXECUTED: the remote side refused before executing (e.g.
     * rejected/auth failure). DEFINITELY_EXECUTED: the outcome proves the
     * operation ran (success or remote processing failure). INDETERMINATE:
     * no proof either way (timeout/unavailable/transport failure), which is
     * the only certainty that may lead to a retry.
     */
    enum ExecutionCertainty: string
    {
        case DEFINITELY_NOT_EXECUTED = 'definitely_not_executed';
        case DEFINITELY_EXECUTED = 'definitely_executed';
        case INDETERMINATE = 'indeterminate';
    }
}
