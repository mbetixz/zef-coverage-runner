<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {
    enum ReplayDecision: string
    {
        case NOT_REQUIRED = 'not_required';
        case ACCEPT = 'accept';
        case DUPLICATE = 'duplicate';
        case STALE = 'stale';
        case REJECTED = 'rejected';
        case UNAVAILABLE = 'unavailable';
    }
}
