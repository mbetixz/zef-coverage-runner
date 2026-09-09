<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {
    enum SecurityVerdict: string
    {
        case ALLOW = 'allow';
        case DENY = 'deny';
    }
}
