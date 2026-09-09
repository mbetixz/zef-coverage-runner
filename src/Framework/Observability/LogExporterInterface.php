<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    interface LogExporterInterface
    {
        /** @param list<LogRecord> $records */
        public function exportLogs(array $records): void;
        public function shutdown(): void;
    }
}
