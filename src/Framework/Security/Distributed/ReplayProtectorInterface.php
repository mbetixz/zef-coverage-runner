<?php

declare(strict_types=1);

namespace Zef\Framework\Security\Distributed {
    interface ReplayProtectorInterface
    {
        public function check(?string $replayId, int $nowMs): ReplayResult;
    }
}
