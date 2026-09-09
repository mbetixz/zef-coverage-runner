<?php
declare(strict_types=1);
require __DIR__.'/_assert.php';

$root = dirname(__DIR__, 2);
$source = (string) file_get_contents($root.'/src/Framework/Observability/Correlation.php');
architecture_check(str_contains($source, 'final readonly class CorrelationContext'), 'W4 immutable correlation context exists');
architecture_check(str_contains($source, 'final readonly class CorrelationHeaders'), 'W4 immutable propagation headers exist');
architecture_check(str_contains($source, 'final class CorrelationPropagator'), 'W4 propagation boundary exists');
architecture_check(str_contains($source, 'interface CorrelationContextCarrierInterface'), 'W4 explicit context carrier exists');
architecture_check(str_contains($source, 'MAX_PROPAGATION_BYTES = 8192'), 'W4 propagation hard size ceiling exists');
architecture_check(str_contains($source, 'MAX_TRACESTATE_BYTES = 512'), 'W4 tracestate hard ceiling exists');
architecture_check(str_contains($source, 'MAX_ATTRIBUTES = 16'), 'W4 attribute count bound exists');
architecture_check(str_contains($source, 'MAX_ATTRIBUTE_BYTES = 4096'), 'W4 aggregate attribute bound exists');
architecture_check(str_contains($source, 'public static function disabled(): null'), 'W4 disabled telemetry API exists');
architecture_check(str_contains($source, 'sha256:'), 'W4 deterministic sensitive-value redaction exists');
architecture_check(str_contains($source, 'public static function extract('), 'W4 inbound extraction path exists');
architecture_check(!str_contains($source, '$GLOBALS'), 'W4 has no global mutable state');
architecture_check(!str_contains($source, '$_SESSION'), 'W4 has no PHP request/session correlation state');
architecture_check(!str_contains($source, 'Swoole'), 'W4 remains Swoole-neutral');
architecture_check(!str_contains($source, 'React\\'), 'W4 remains React-neutral');
architecture_check(!str_contains($source, 'Amp\\'), 'W4 remains Amp-neutral');
architecture_check(!str_contains($source, 'OpenTelemetry\\'), 'W4 core has no mandatory OpenTelemetry SDK dependency');
architecture_check(!str_contains($source, 'OTEL_'), 'W4 core has no telemetry environment coupling');
architecture_check(!str_contains($source, 'Promise'), 'W4 introduces no async Promise contract');
architecture_check(!str_contains($source, 'Future'), 'W4 introduces no async Future contract');
architecture_check(!str_contains($source, 'sleep('), 'W4 does not block or schedule execution');
architecture_check(!str_contains($source, 'usleep('), 'W4 does not block or schedule execution');
echo "G4.8 W4 correlation governance PASS".PHP_EOL;
