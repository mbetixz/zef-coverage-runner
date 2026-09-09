<?php

declare(strict_types=1);

namespace Zef\Module\Health {
    // Compatibility aggregate retained for explicit legacy requires (D3/D4).
}

namespace {
    require_once __DIR__ . '/Health/LiveHandler.php';
    require_once __DIR__ . '/Health/ReadyHandler.php';
    require_once __DIR__ . '/Health/ConfigProvider.php';
}
