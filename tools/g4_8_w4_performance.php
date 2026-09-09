<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Zef\Framework\Observability\CorrelationPropagator;

$iterations = (int) ($argv[1] ?? 100000);
if ($iterations < 1000 || $iterations > 1000000) {
    throw new InvalidArgumentException('iterations must be between 1000 and 1000000');
}

$measure = static function (callable $fn, int $iterations): array {
    gc_collect_cycles();
    $startMem = memory_get_usage(true);
    $start = hrtime(true);
    $baseline = CorrelationPropagator::disabled();
    for ($i = 0; $i < $iterations; ++$i) {
        $fn($i);
    }
    $elapsedNs = hrtime(true) - $start;
    $peak = memory_get_peak_usage(true);
    return [
        'elapsed_ms' => $elapsedNs / 1_000_000,
        'ns_per_op' => $elapsedNs / $iterations,
        'peak_memory_delta_bytes' => max(0, $peak - $startMem),
        'disabled_identity' => $baseline === null,
    ];
};

$disabled = $measure(static function (): void {
    $context = CorrelationPropagator::disabled();
    if ($context !== null) throw new RuntimeException('Disabled path produced context.');
    if (CorrelationPropagator::inject(null) !== null) throw new RuntimeException('Disabled path produced headers.');
}, $iterations);

$enabled = $measure(static function (): void {
    $context = CorrelationPropagator::extract(
        '00-11111111111111111111111111111111-2222222222222222-01',
        'vendor=value',
        'benchmark.operation',
        null,
        ['operation.class' => 'benchmark'],
    );
    if ($context === null || CorrelationPropagator::inject($context) === null) {
        throw new RuntimeException('Enabled correlation path failed.');
    }
}, $iterations);

$overhead = $disabled['ns_per_op'] > 0 ? $enabled['ns_per_op'] - $disabled['ns_per_op'] : 0.0;
printf("G4.8 W4 correlation performance iterations=%d\n", $iterations);
printf("disabled_ns_per_op=%.2f\n", $disabled['ns_per_op']);
printf("enabled_ns_per_op=%.2f\n", $enabled['ns_per_op']);
printf("enabled_minus_disabled_ns_per_op=%.2f\n", $overhead);
printf("disabled_peak_memory_delta_bytes=%d\n", $disabled['peak_memory_delta_bytes']);
printf("enabled_peak_memory_delta_bytes=%d\n", $enabled['peak_memory_delta_bytes']);
printf("telemetry_disabled_context_is_null=%s\n", $disabled['disabled_identity'] ? 'true' : 'false');
