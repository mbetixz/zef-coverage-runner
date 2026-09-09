<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    /** Vendor-neutral W3C propagation boundary. */
    final class CorrelationPropagator
    {
        /**
         * Inbound propagation is untrusted. Invalid or oversized input is rejected by returning null.
         * @param array<string, string|int|float|bool|null> $attributes
         */
        public static function extract(
            ?string $traceParent,
            ?string $traceState,
            string $operationId,
            ?string $idempotencyKey = null,
            array $attributes = [],
        ): ?CorrelationContext {
            if ($traceParent === null || strlen($traceParent) > CorrelationContext::MAX_TRACEPARENT_BYTES) {
                return null;
            }
            if ($traceState !== null && strlen($traceState) > CorrelationContext::MAX_TRACESTATE_BYTES) {
                return null;
            }
            $value = trim($traceParent);
            if (strlen($value) !== CorrelationContext::MAX_TRACEPARENT_BYTES) {
                return null;
            }
            if (preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i', $value, $m) !== 1) {
                return null;
            }
            try {
                return new CorrelationContext(strtolower($m[1]), strtolower($m[2]), strtolower($m[3]), $traceState, $operationId, $idempotencyKey, $attributes);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        public static function inject(?CorrelationContext $context): ?CorrelationHeaders
        {
            return $context === null ? null : new CorrelationHeaders($context->traceParent(), $context->traceState);
        }

        public static function disabled(): null
        {
            // Deliberately null: disabled telemetry creates no observability context object.
            return null;
        }
    }
}
