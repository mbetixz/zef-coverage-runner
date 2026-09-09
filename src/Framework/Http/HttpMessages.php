<?php

declare(strict_types=1);

namespace Zef\Framework\Http {
    // Compatibility namespace retained for consumers that explicitly require the aggregate.
}

namespace {
    require_once __DIR__ . '/Stream.php';
    require_once __DIR__ . '/Uri.php';
    require_once __DIR__ . '/MessageBase.php';
    require_once __DIR__ . '/Request.php';
    require_once __DIR__ . '/ServerRequest.php';
    require_once __DIR__ . '/Response.php';
    require_once __DIR__ . '/UploadedFile.php';
}
