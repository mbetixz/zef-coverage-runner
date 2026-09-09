<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    // Compatibility aggregate retained for explicit legacy requires.
}

namespace {
    require_once __DIR__ . '/MetricExporterInterface.php';
    require_once __DIR__ . '/LogRecord.php';
    require_once __DIR__ . '/LogExporterInterface.php';
    require_once __DIR__ . '/SpanExporterInterface.php';
    require_once __DIR__ . '/SpanInterface.php';
    require_once __DIR__ . '/TracerInterface.php';
    require_once __DIR__ . '/MeterInterface.php';
}
