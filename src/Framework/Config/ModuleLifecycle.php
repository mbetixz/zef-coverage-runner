<?php

declare(strict_types=1);

namespace Zef\Framework\Config {
    // Compatibility sub-aggregate retained for explicit legacy requires.
    // Public declarations moved to per-type files: interface ModuleRegistrar
    // (ModuleRegistrar.php), class ModuleContext (ModuleContext.php),
    // interface ModuleInterface (ModuleInterface.php), abstract class
    // AbstractModule (AbstractModule.php), class ConfigProviderModule
    // (ConfigProviderModule.php), class ModuleRegistry (ModuleRegistry.php),
    // class ModuleConfigProvider (ModuleConfigProvider.php), interface
    // ConfigProviderInterface (ConfigProviderInterface.php).
}

namespace {
    require_once __DIR__ . '/ModuleRegistrar.php';
    require_once __DIR__ . '/ModuleContext.php';
    require_once __DIR__ . '/ModuleInterface.php';
    require_once __DIR__ . '/AbstractModule.php';
    require_once __DIR__ . '/ConfigProviderModule.php';
    require_once __DIR__ . '/ModuleRegistry.php';
    require_once __DIR__ . '/ModuleConfigProvider.php';
    require_once __DIR__ . '/ConfigProviderInterface.php';
}
