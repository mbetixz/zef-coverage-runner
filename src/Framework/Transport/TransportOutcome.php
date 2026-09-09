<?php

declare(strict_types=1);

namespace Zef\Framework\Transport {
    /**
     * Classification of a remote transport send attempt.
     *
     * SUCCESS: acknowledged by the remote side. REJECTED: refused before
     * execution. TIMEOUT / UNAVAILABLE / TRANSPORT_FAILURE: no definitive
     * answer. AUTHENTICATION_FAILURE / AUTHORIZATION_FAILURE: refused on
     * credentials or permissions. REMOTE_PROCESSING_FAILURE: executed but
     * failed remotely. INDETERMINATE: no proof either way (the only outcome
     * that may require an idempotent retry). CANCELLED: aborted before
     * completion.
     */
    enum TransportOutcome: string
    {
        case SUCCESS = 'success';
        case REJECTED = 'rejected';
        case TIMEOUT = 'timeout';
        case UNAVAILABLE = 'unavailable';
        case TRANSPORT_FAILURE = 'transport_failure';
        case AUTHENTICATION_FAILURE = 'authentication_failure';
        case AUTHORIZATION_FAILURE = 'authorization_failure';
        case REMOTE_PROCESSING_FAILURE = 'remote_processing_failure';
        case INDETERMINATE = 'indeterminate';
        case CANCELLED = 'cancelled';
    }
}
