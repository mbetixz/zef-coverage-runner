<?php
declare(strict_types=1);
require __DIR__ . '/_assert.php';
$root = dirname(__DIR__, 2);
$s = architecture_source($root);
$extract = static function (string $src): array {
    preg_match_all('/^\s*(?:(?:final|abstract|readonly) )*(?:class|interface)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $c);
    preg_match_all('/^\s*public function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $src, $m);
    $classes = array_values(array_unique($c[1])); sort($classes);
    $methods = array_values(array_unique($m[1])); sort($methods);
    return [$classes, $methods];
};

$baselineRaw = json_decode((string) file_get_contents($root.'/tests/Architecture/public-surface-baseline-beta1-h3.json'), true, 512, JSON_THROW_ON_ERROR);
$deltaRaw = json_decode((string) file_get_contents($root.'/tests/Architecture/public-surface-delta-v2.5.0-beta1-h3.json'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($baselineRaw) || !isset($baselineRaw['classes'], $baselineRaw['methods']) || !is_array($baselineRaw['classes']) || !is_array($baselineRaw['methods'])) {
    throw new RuntimeException('Invalid H3 public surface baseline.');
}
if (!is_array($deltaRaw) || !isset($deltaRaw['added_classes'], $deltaRaw['removed_classes'], $deltaRaw['added_public_methods'], $deltaRaw['removed_public_methods']) || !is_array($deltaRaw['added_classes']) || !is_array($deltaRaw['removed_classes']) || !is_array($deltaRaw['added_public_methods']) || !is_array($deltaRaw['removed_public_methods'])) {
    throw new RuntimeException('Invalid H3 public surface delta.');
}
$baseline = $baselineRaw;
$delta = $deltaRaw;
[$classes, $methods] = $extract($s);
architecture_check($classes === $baseline['classes'], 'H3 public class surface is stable');
architecture_check($methods === $baseline['methods'], 'H3 public method surface is stable');
architecture_check($delta['added_classes'] === ['MessageBusInterface','MessageContext','MessageEnvelope','MessageHandlerInterface','MessageMiddlewareInterface','MessageResult','MessageSerializerInterface','MessageTransportInterface'], 'H3 public class additions are explicit');
architecture_check($delta['removed_classes'] === [], 'H3 removes no public classes');
architecture_check($delta['added_public_methods'] === ['deserialize','send','serialize'], 'H3 public method additions are explicit');
architecture_check($delta['removed_public_methods'] === [], 'H3 removes no public methods');
