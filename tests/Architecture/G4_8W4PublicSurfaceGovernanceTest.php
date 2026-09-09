<?php
declare(strict_types=1);
require __DIR__.'/_assert.php';

$root = dirname(__DIR__, 2);
$source = (string) file_get_contents($root.'/src/Framework/Observability/Correlation.php');
$expectedTypes = ['CorrelationContext','CorrelationHeaders','CorrelationPropagator','CorrelationContextCarrierInterface','ExplicitCorrelationContextCarrier'];
foreach ($expectedTypes as $type) {
    architecture_check((bool) preg_match('/\\b(?:class|interface)\\s+'.preg_quote($type, '/').'\\b/', $source), 'W4 declared public type: '.$type);
}
foreach (['traceParent','propagationBytes','redactedAttributes','encodedBytes','extract','inject','disabled','correlationContext'] as $method) {
    architecture_check(str_contains($source, 'public function '.$method.'(') || str_contains($source, 'public static function '.$method.'('), 'W4 declared public method: '.$method);
}
$delta = json_decode((string) file_get_contents(__DIR__.'/public-surface-delta-v2.5.0-beta1-g4.8-w4.json'), true, flags: JSON_THROW_ON_ERROR);
/** @var array{classes?: array<mixed>, methods?: array<mixed>, removed?: array<mixed>} $delta */
architecture_check(count($delta['classes'] ?? []) === 5, 'W4 delta declares exactly 5 public types');
architecture_check(count($delta['methods'] ?? []) === 8, 'W4 delta declares exactly 8 named public methods');
architecture_check(count($delta['removed'] ?? []) === 0, 'W4 delta declares zero removals');
echo "G4.8 W4 public-surface governance PASS".PHP_EOL;
