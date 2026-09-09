<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    /**
     * Reason a retry decision was made. Terminal reasons (allowed=false) mark
     * the delivery as done: success, at-most-once mode, missing idempotency
     * guarantee for a side-effecting operation, security/resource denial,
     * deadline/budget/attempt exhaustion, non-retryable outcome, or definitive
     * execution. SAFE_TO_RETRY is the only reason that permits a retry.
     */
    enum RetryDecisionReason: string
    {
        case SUCCESS = 'success';
        case AT_MOST_ONCE = 'at_most_once';
        case IDEMPOTENCY_GUARANTEE_REQUIRED = 'idempotency_guarantee_required';
        case SECURITY_DENIED = 'security_denied';
        case RESOURCE_DENIED = 'resource_denied';
        case DEADLINE_EXPIRED = 'deadline_expired';
        case ATTEMPT_LIMIT_REACHED = 'attempt_limit_reached';
        case RETRY_BUDGET_EXHAUSTED = 'retry_budget_exhausted';
        case NOT_RETRYABLE = 'not_retryable';
        case SAFE_TO_RETRY = 'safe_to_retry';
        case DEFINITIVELY_EXECUTED = 'definitively_executed';
    }
}
