<?php

declare(strict_types=1);

namespace Zef\Framework\Policy {

    final readonly class ArchitecturePolicy
    {
        public function __construct(
            public int $maxCrossModuleRefs = 0,
            public int $maxServiceRegistrations = 10000,
            public int $maxRouteRegistrations = 10000,
            public int $maxResolutionDepth = 256,
        ) {
            if ($this->maxCrossModuleRefs < 0) {
                throw new \InvalidArgumentException('maxCrossModuleRefs must be >= 0.');
            }
            if ($this->maxServiceRegistrations < 1) {
                throw new \InvalidArgumentException('maxServiceRegistrations must be >= 1.');
            }
            if ($this->maxRouteRegistrations < 1) {
                throw new \InvalidArgumentException('maxRouteRegistrations must be >= 1.');
            }
            if ($this->maxResolutionDepth < 1) {
                throw new \InvalidArgumentException('maxResolutionDepth must be >= 1.');
            }
        }
    }
}
