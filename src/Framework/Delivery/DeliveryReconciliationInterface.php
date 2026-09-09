<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    use Zef\Framework\Transport\RemoteTransportResult;

    /**
     * Reconciliation hook consulted for indeterminate outcomes: given the
     * operation, returns the definitive remote result if it can be determined
     * (e.g. by querying the remote side), or null to remain indeterminate.
     */
    interface DeliveryReconciliationInterface
    {
        public function reconcile(DeliveryOperation $operation): ?RemoteTransportResult;
    }
}
