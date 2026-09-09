<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$file = $root.'/src/Framework/Message/Messages.php';
$code = file_get_contents($file);
if ($code === false) { throw new RuntimeException('Unable to read H3 message foundation.'); }

$expected = [
    'MessageBusInterface',
    'MessageSerializerInterface',
    'MessageHandlerInterface',
    'MessageMiddlewareInterface',
    'MessageTransportInterface',
];
foreach ($expected as $name) {
    if (substr_count($code, 'interface '.$name) !== 1) {
        throw new RuntimeException("Expected exactly one H3 contract: {$name}");
    }
}

foreach (['spiral\\roadrunner', 'kafka', 'rabbitmq', 'nats', 'sqs', 'redis'] as $forbidden) {
    if (stripos($code, $forbidden) !== false) {
        throw new RuntimeException("H3 foundation must remain vendor-neutral: {$forbidden}");
    }
}

if (substr_count($code, 'interface ') !== 5) {
    throw new RuntimeException('H3 foundation must contain exactly five contracts.');
}

echo "H3 foundation governance: PASS\n";
