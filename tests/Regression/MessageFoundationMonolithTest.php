<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$path = $root.'/zef_framework_v2.5.0-beta1.php';
$source = file_get_contents($path);
if ($source === false) throw new RuntimeException('Cannot read canonical monolith.');
$required = [
    'namespace Zef\\Framework\\Message',
    'interface MessageBusInterface',
    'interface MessageSerializerInterface',
    'interface MessageHandlerInterface',
    'interface MessageMiddlewareInterface',
    'interface MessageTransportInterface',
];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) throw new RuntimeException("Missing H3 monolith contract: {$needle}");
}
echo "Message foundation monolith: PASS\n";
