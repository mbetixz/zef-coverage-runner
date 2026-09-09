<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    // Compatibility aggregate retained for explicit legacy requires.
}

namespace {
    require_once __DIR__ . '/SpanData.php';
    require_once __DIR__ . '/Span.php';
    require_once __DIR__ . '/NoopSpan.php';
    require_once __DIR__ . '/NoopTracer.php';
}
