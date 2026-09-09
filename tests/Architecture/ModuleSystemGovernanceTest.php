<?php
declare(strict_types=1);
require_once __DIR__ . '/../../zef_framework_v2.5.0-beta1.php';

use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Config\ModuleRegistry;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$valid = new ModuleDefinition('billing', dependencies: ['Core']);
$assert($valid->dependencies === ['core'], 'module dependency names must normalize case');
$roundtrip = ModuleDefinition::fromArray('billing', $valid->toArray());
$assert($roundtrip->dependencies === ['core'], 'module dependency roundtrip drift');

$missing = new ModuleRegistry();
$missing->add(new class(new ModuleDefinition('billing', dependencies: ['core'])) extends \Zef\Framework\Config\AbstractModule {});
$failed = false;
try { $missing->resolveOrder(); } catch (\Zef\Framework\Exception\InvalidConfigurationException) { $failed = true; }
$assert($failed, 'missing module dependency accepted');

$cycle = new ModuleRegistry();
$cycle->add(new class(new ModuleDefinition('a', dependencies: ['b'])) extends \Zef\Framework\Config\AbstractModule {});
$cycle->add(new class(new ModuleDefinition('b', dependencies: ['a'])) extends \Zef\Framework\Config\AbstractModule {});
$failed = false;
try { $cycle->resolveOrder(); } catch (\Zef\Framework\Exception\InvalidConfigurationException) { $failed = true; }
$assert($failed, 'cyclic module dependency accepted');

echo "ModuleSystemGovernanceTest: PASS\n";
