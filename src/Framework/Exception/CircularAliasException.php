<?php

declare(strict_types=1);

namespace Zef\Framework\Exception {
    final class CircularAliasException extends \RuntimeException
    {
        /** @param list<string> $chain */
        public function __construct(public readonly array $chain)
        {
            parent::__construct('Circular alias detected: ' . implode(' -> ', $chain));
        }
    }
}
