<?php

declare(strict_types=1);

namespace Zef\Framework\Qualification;

enum QualificationStatus: string
{
    case NOT_STARTED = 'not_started';
    case IN_PROGRESS = 'in_progress';
    case PASS = 'pass';
    case FAIL = 'fail';
    case WAIVED = 'waived';
}
