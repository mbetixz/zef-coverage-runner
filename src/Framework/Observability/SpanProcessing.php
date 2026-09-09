<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    // Compatibility aggregate retained for explicit legacy requires.
}

namespace {
    require_once __DIR__ . '/InMemorySpanExporter.php';
    require_once __DIR__ . '/BatchSpanProcessor.php';
    require_once __DIR__ . '/Tracer.php';
}
