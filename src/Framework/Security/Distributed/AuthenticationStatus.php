<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {
    enum AuthenticationStatus: string
    {
        case UNAUTHENTICATED = 'unauthenticated';
        case AUTHENTICATED = 'authenticated';
        case FAILED = 'failed';
        case UNAVAILABLE = 'unavailable';
        case EXPIRED = 'expired';
    }
}
