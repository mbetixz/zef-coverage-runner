<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    interface MeterInterface
    {
        /** @param array<string,mixed> $attributes */
        public function increment(string $name, int|float $value = 1, array $attributes = []): void;
        /** @param array<string,mixed> $attributes */
        public function observe(string $name, float $value, array $attributes = []): void;
        /** @return array<string,array{count:int|float,sum:float,attributes:array<string,mixed>}> */
        public function snapshot(): array;
    }
}
