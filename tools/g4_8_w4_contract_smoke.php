<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Zef\Framework\Observability\CorrelationPropagator;

$ctx = CorrelationPropagator::extract(
    '00-11111111111111111111111111111111-2222222222222222-01',
    'vendor=value',
    'smoke.operation',
    'smoke-idem',
    ['operation.class' => 'smoke'],
);
if ($ctx === null) {
    fwrite(STDERR, "G4.8 W4 native contract smoke FAIL: extraction\n");
    exit(1);
}
$headers = CorrelationPropagator::inject($ctx);
if ($headers === null || $headers->encodedBytes() > 8192) {
    fwrite(STDERR, "G4.8 W4 native contract smoke FAIL: propagation bounds\n");
    exit(1);
}
if (CorrelationPropagator::extract('bad', null, 'smoke.operation') !== null) {
    fwrite(STDERR, "G4.8 W4 native contract smoke FAIL: malformed input accepted\n");
    exit(1);
}
if (CorrelationPropagator::disabled() !== null || CorrelationPropagator::inject(null) !== null) {
    fwrite(STDERR, "G4.8 W4 native contract smoke FAIL: disabled path allocates context\n");
    exit(1);
}
echo "G4.8 W4 native contract smoke PASS".PHP_EOL;
