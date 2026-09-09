<?php

declare(strict_types=1);

namespace Zef\Framework\Validation {

    final class TrustedHostValidator
    {
        /**
         * @param list<string> $trustedHosts
         */
        public function __construct(private readonly array $trustedHosts = [])
        {
        }

        public function assert(string $host): void
        {
            if ($host === '' || $this->trustedHosts === []) {
                return;
            }
            $normalize = static function (string $value): string {
                $value = strtolower(trim($value));
                if (strlen($value) >= 2 && $value[0] === '[' && $value[strlen($value) - 1] === ']') {
                    $value = substr($value, 1, -1);
                }
                return $value;
            };
            $normalized = $normalize($host);
            foreach ($this->trustedHosts as $allowed) {
                /** @var string $allowed */
                if ($normalized === $normalize($allowed)) {
                    return;
                }
            }
            throw new \InvalidArgumentException("Untrusted host: {$host}.");
        }
    }
}
