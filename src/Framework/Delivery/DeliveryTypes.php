<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    // Compatibility sub-aggregate retained for explicit legacy requires.
    // Public declarations moved to per-type files: enum DeliverySafety
    // (DeliverySafety.php), enum DeliveryMode (DeliveryMode.php), enum
    // ExecutionCertainty (ExecutionCertainty.php), enum IdempotencyClaim
    // (IdempotencyClaim.php), enum DeliveryState (DeliveryState.php), enum
    // RetryDecisionReason (RetryDecisionReason.php), class DeliveryOperation
    // (DeliveryOperation.php), class DeliveryObservation
    // (DeliveryObservation.php), class DeliveryAttempt (DeliveryAttempt.php).
}

namespace {
    require_once __DIR__ . '/DeliverySafety.php';
    require_once __DIR__ . '/DeliveryMode.php';
    require_once __DIR__ . '/ExecutionCertainty.php';
    require_once __DIR__ . '/IdempotencyClaim.php';
    require_once __DIR__ . '/DeliveryState.php';
    require_once __DIR__ . '/RetryDecisionReason.php';
    require_once __DIR__ . '/DeliveryOperation.php';
    require_once __DIR__ . '/DeliveryObservation.php';
    require_once __DIR__ . '/DeliveryAttempt.php';
}
