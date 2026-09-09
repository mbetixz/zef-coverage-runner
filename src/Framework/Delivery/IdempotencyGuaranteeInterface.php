<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    /**
     * A store implementation that can prove it supports an operation is an
     * idempotency guarantee for DeliverySemanticsEvaluator. Implementations
     * must be consistent with IdempotencyStoreInterface for the same key.
     */
    interface IdempotencyGuaranteeInterface
    {
        public function supports(DeliveryOperation $operation): bool;
    }
}
