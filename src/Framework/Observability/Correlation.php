<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    // Compatibility aggregate retained for explicit legacy requires.
    // Public declarations moved to per-type files (D2 structural decomposition):
    // final readonly class CorrelationContext, final readonly class CorrelationHeaders,
    // final class CorrelationPropagator, interface CorrelationContextCarrierInterface,
    // class ExplicitCorrelationContextCarrier.
    // Bounds retained per-type: MAX_PROPAGATION_BYTES = 8192,
    // MAX_TRACESTATE_BYTES = 512, MAX_ATTRIBUTES = 16,
    // MAX_ATTRIBUTE_BYTES = 4096.
    // W4 surface retained per-type: public function traceParent(,
    // public function propagationBytes(, public function redactedAttributes(,
    // public function encodedBytes(, public static function extract(,
    // public static function inject(, public static function disabled(): null,
    // public function correlationContext(, sha256: deterministic redaction.
}

namespace {
    require_once __DIR__ . '/CorrelationContext.php';
    require_once __DIR__ . '/CorrelationHeaders.php';
    require_once __DIR__ . '/CorrelationPropagator.php';
    require_once __DIR__ . '/CorrelationContextCarrierInterface.php';
    require_once __DIR__ . '/ExplicitCorrelationContextCarrier.php';
}
