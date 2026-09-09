<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    // Compatibility aggregate retained for explicit legacy requires.
    // Public declarations moved to modules: enum DeliverySafety, enum DeliveryMode,
    // enum ExecutionCertainty, enum IdempotencyClaim, enum DeliveryState,
    // enum RetryDecisionReason, class DeliveryOperation, class DeliveryObservation,
    // class DeliveryAttempt, class RetryPolicy, class RetryContext, class RetryDecision,
    // interface IdempotencyGuaranteeInterface, interface IdempotencyStoreInterface,
    // class IdempotencyRecord, class BoundedInMemoryIdempotencyStore,
    // interface DeliveryReconciliationInterface, class DeliveryResult, class DeliveryStateMachine,
    // class DeliverySemanticsEvaluator, maxAttempts, maxCumulativeDelayMs, deadlineMs, DEFINITIVELY_EXECUTED,
    // IDEMPOTENCY_GUARANTEE_REQUIRED, SECURITY_DENIED, RESOURCE_DENIED.
    // Public methods retained by modules: public function delayMs(), public function supports(),
    // public function claim(), public function complete(), public function completedResult(),
    // public function reconcile(), public function state(), public function transition(),
    // public function executionCertainty(), public function shouldRetry(),
    // public function shouldRetryObservation().
    // One type per file (C5 structural decomposition):
    // DeliverySafety.php, DeliveryMode.php, ExecutionCertainty.php, IdempotencyClaim.php,
    // DeliveryState.php, RetryDecisionReason.php, DeliveryOperation.php,
    // DeliveryObservation.php, DeliveryAttempt.php, RetryPolicy.php, RetryContext.php,
    // RetryDecision.php, IdempotencyGuaranteeInterface.php, IdempotencyStoreInterface.php,
    // IdempotencyRecord.php, BoundedInMemoryIdempotencyStore.php,
    // DeliveryReconciliationInterface.php, DeliveryResult.php, DeliveryStateMachine.php,
    // DeliverySemanticsEvaluator.php.
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
    require_once __DIR__ . '/RetryPolicy.php';
    require_once __DIR__ . '/RetryContext.php';
    require_once __DIR__ . '/RetryDecision.php';
    require_once __DIR__ . '/IdempotencyGuaranteeInterface.php';
    require_once __DIR__ . '/IdempotencyStoreInterface.php';
    require_once __DIR__ . '/IdempotencyRecord.php';
    require_once __DIR__ . '/BoundedInMemoryIdempotencyStore.php';
    require_once __DIR__ . '/DeliveryReconciliationInterface.php';
    require_once __DIR__ . '/DeliveryResult.php';
    require_once __DIR__ . '/DeliveryStateMachine.php';
    require_once __DIR__ . '/DeliverySemanticsEvaluator.php';
}
