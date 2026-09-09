<?php

declare(strict_types=1);

namespace Zef\Middleware {
    // Compatibility aggregate retained for explicit legacy requires (D3/D4).
}

namespace {
    require_once __DIR__ . '/ErrorLogger.php';
    require_once __DIR__ . '/ErrorResponseFactory.php';
    require_once __DIR__ . '/GlobalErrorHandler.php';
    require_once __DIR__ . '/TimingMiddleware.php';
    require_once __DIR__ . '/CorsMiddleware.php';
    require_once __DIR__ . '/SecurityHeadersMiddleware.php';
    require_once __DIR__ . '/ConfigProvider.php';
}
