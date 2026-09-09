<?php

declare(strict_types=1);

namespace Zef\Framework\Runtime {
    final readonly class RuntimeExtensionContext
    {
        /** @param array<string, mixed> $attributes */
        public function __construct(
            public RuntimeIdentity $identity,
            public RuntimeExtensionState $state,
            public array $attributes = [],
        ) {
            foreach ($attributes as $key => $value) {
                if ($key === '') {
                    throw new \InvalidArgumentException('Runtime extension attribute keys must be non-empty strings.');
                }
                if ($value !== null && !is_scalar($value)) {
                    throw new \InvalidArgumentException('Runtime extension attributes must be scalar or null.');
                }
            }
        }
    }

}
