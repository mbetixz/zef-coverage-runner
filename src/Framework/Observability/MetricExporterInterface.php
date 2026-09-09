<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    interface MetricExporterInterface
    {
        /** @param array<string,array{count:int|float,sum:float,attributes:array<string,mixed>}> $metrics */
        public function exportMetrics(array $metrics): void;
        public function shutdown(): void;
    }
}
