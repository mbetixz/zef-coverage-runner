<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    use Zef\Framework\Transport\RemoteTransportResult;

    /**
     * Immutable snapshot of one delivery attempt's transport outcome paired
     * with its classified execution certainty.
     *
     * result is the raw transport response observed for the attempt;
     * certainty is the ExecutionCertainty derived from that outcome by
     * DeliverySemanticsEvaluator::executionCertainty(). The pair is consumed
     * by shouldRetryObservation() so retry decisions never re-derive
     * certainty from the outcome alone.
     */
    final readonly class DeliveryObservation
    {
        public function __construct(
            public RemoteTransportResult $result,
            public ExecutionCertainty $certainty,
        ) {
        }
    }
}
