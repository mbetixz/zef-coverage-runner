<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    // Compatibility aggregate retained for explicit legacy requires.
}

namespace {
    require_once __DIR__ . '/Contracts.php';
    require_once __DIR__ . '/TraceContext.php';
    require_once __DIR__ . '/Spans.php';
    require_once __DIR__ . '/SpanProcessing.php';
    require_once __DIR__ . '/CounterMeter.php';
    require_once __DIR__ . '/TelemetrySanitizer.php';
    require_once __DIR__ . '/Telemetry.php';
    require_once __DIR__ . '/OtlpHttpJsonExporter.php';
}
