<?php

declare(strict_types=1);

namespace Zef\Framework\Config {
    // Canonical aggregate: config types are physically one-class-per-file.
    // ModuleDefinition (ModuleDefinition.php), ConfigAggregator
    // (ConfigAggregator.php), ModuleRegistrar (ModuleRegistrar.php),
    // ModuleContext (ModuleContext.php), ModuleInterface (ModuleInterface.php),
    // AbstractModule (AbstractModule.php), ConfigProviderModule
    // (ConfigProviderModule.php), ModuleRegistry (ModuleRegistry.php),
    // ModuleConfigProvider (ModuleConfigProvider.php), ConfigProviderInterface
    // (ConfigProviderInterface.php), SecretValue (SecretValue.php),
    // SecretProviderInterface (SecretProviderInterface.php),
    // EnvironmentSecretProvider (EnvironmentSecretProvider.php),
    // ConfigurationSnapshot (ConfigurationSnapshot.php), ConfigurationGovernance
    // (ConfigurationGovernance.php).
}

namespace {
    require_once __DIR__ . '/ModuleDefinition.php';
    require_once __DIR__ . '/ModuleRegistrar.php';
    require_once __DIR__ . '/ModuleContext.php';
    require_once __DIR__ . '/ModuleInterface.php';
    require_once __DIR__ . '/AbstractModule.php';
    require_once __DIR__ . '/ConfigProviderModule.php';
    require_once __DIR__ . '/ModuleRegistry.php';
    require_once __DIR__ . '/ModuleConfigProvider.php';
    require_once __DIR__ . '/ConfigProviderInterface.php';
    require_once __DIR__ . '/ConfigAggregator.php';
    require_once __DIR__ . '/SecretValue.php';
    require_once __DIR__ . '/SecretProviderInterface.php';
    require_once __DIR__ . '/EnvironmentSecretProvider.php';
    require_once __DIR__ . '/ConfigurationSnapshot.php';
    require_once __DIR__ . '/ConfigurationGovernance.php';
}
