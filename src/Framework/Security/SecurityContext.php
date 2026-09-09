<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    final readonly class SecurityContext
    {
        public function __construct(
            public string $requestId,
            public string $clientIp,
            public ?string $origin,
            public bool $secure,
        ) {
        }
    }
}
