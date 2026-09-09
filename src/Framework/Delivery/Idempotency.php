<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    // Compatibility sub-aggregate retained for explicit legacy requires.
    // Public declarations moved to per-type files: interface
    // IdempotencyGuaranteeInterface (IdempotencyGuaranteeInterface.php),
    // interface IdempotencyStoreInterface (IdempotencyStoreInterface.php),
    // class IdempotencyRecord (IdempotencyRecord.php), class
    // BoundedInMemoryIdempotencyStore (BoundedInMemoryIdempotencyStore.php).
}

namespace {
    require_once __DIR__ . '/IdempotencyGuaranteeInterface.php';
    require_once __DIR__ . '/IdempotencyStoreInterface.php';
    require_once __DIR__ . '/IdempotencyRecord.php';
    require_once __DIR__ . '/BoundedInMemoryIdempotencyStore.php';
}
