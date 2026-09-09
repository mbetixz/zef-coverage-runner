<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    // Compatibility sub-aggregate retained for explicit legacy requires.
    // Public declarations moved to per-type files: interface
    // DeliveryReconciliationInterface (DeliveryReconciliationInterface.php),
    // class DeliveryResult (DeliveryResult.php), class DeliveryStateMachine
    // (DeliveryStateMachine.php), class DeliverySemanticsEvaluator
    // (DeliverySemanticsEvaluator.php).
}

namespace {
    require_once __DIR__ . '/DeliveryReconciliationInterface.php';
    require_once __DIR__ . '/DeliveryResult.php';
    require_once __DIR__ . '/DeliveryStateMachine.php';
    require_once __DIR__ . '/DeliverySemanticsEvaluator.php';
}
