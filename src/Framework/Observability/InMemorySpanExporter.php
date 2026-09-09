<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    final class InMemorySpanExporter implements SpanExporterInterface
    {
        /** @var list<SpanData> */ private array $spans = [];
        #[\Override] public function export(array $spans): void
        {
            foreach ($spans as $span) {
                $this->spans[] = $span;
            }
        }
        #[\Override] public function shutdown(): void
        {
        }
        /** @return list<SpanData> */ public function spans(): array
        {
            return $this->spans;
        }
        public function reset(): void
        {
            $this->spans = [];
        }
    }
}
