<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    interface SpanExporterInterface
    {
        /** @param list<SpanData> $spans */
        public function export(array $spans): void;
        public function shutdown(): void;
    }
}
