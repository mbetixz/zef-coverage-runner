<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$pass = 0; $fail = 0;
$check = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    if ($condition) { ++$pass; echo "PASS: {$message}\n"; return; }
    ++$fail; echo "FAIL: {$message}\n";
};

$classes = [
    'Zef\\Framework\\Http\\Stream' => 'Stream.php',
    'Zef\\Framework\\Http\\Response' => 'Response.php',
    'Zef\\Framework\\Http\\ServerRequest' => 'ServerRequest.php',
    'Zef\\Framework\\Http\\RequestFactory' => 'RequestFactory.php',
    'Zef\\Framework\\Observability\\Span' => 'Spans.php',
    'Zef\\Framework\\Observability\\Telemetry' => 'Telemetry.php',
    'Zef\\Framework\\Observability\\OtlpHttpJsonExporter' => 'OtlpHttpJsonExporter.php',
    'Zef\\Framework\\Delivery\\RetryPolicy' => 'RetryPolicy.php',
    'Zef\\Framework\\Delivery\\BoundedInMemoryIdempotencyStore' => 'BoundedInMemoryIdempotencyStore.php',
    'Zef\\Framework\\Delivery\\DeliverySemanticsEvaluator' => 'DeliverySemanticsEvaluator.php',
    'Zef\\Framework\\Config\\ModuleDefinition' => 'ModuleDefinition.php',
    'Zef\\Framework\\Config\\ConfigAggregator' => 'ConfigAggregator.php',
    'Zef\\Framework\\Config\\ConfigurationGovernance' => 'ConfigurationGovernance.php',
    'Zef\\Framework\\Runtime\\RoadRunnerRuntime' => 'RuntimeLifecycle.php',
    'Zef\\Framework\\Runtime\\InMemoryWorker' => 'InMemoryWorker.php',
    'Zef\\Framework\\Runtime\\BlockingSleeper' => 'BlockingSleeper.php',
];
foreach ($classes as $class => $expectedFile) {
    $check(class_exists($class), "autoload {$class}");
    $reflection = new ReflectionClass($class);
    $file = $reflection->getFileName();
    $check(is_string($file) && basename($file) === $expectedFile, "direct mapping {$class} -> {$expectedFile}");
}

$aggregates = [
    $root . '/src/Framework/Http/HttpMessages.php',
    $root . '/src/Framework/Observability/Observability.php',
    $root . '/src/Framework/Delivery/Delivery.php',
    $root . '/src/Framework/Config/Config.php',
    $root . '/src/Framework/Runtime/Runtime.php',
];
foreach ($aggregates as $aggregate) {
    require_once $aggregate;
    require_once $aggregate;
    $check(true, 'compatibility aggregate loads repeatedly: ' . basename($aggregate));
}

printf("Facade compatibility verification: %d pass, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
