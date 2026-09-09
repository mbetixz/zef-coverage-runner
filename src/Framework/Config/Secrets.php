<?php

declare(strict_types=1);

namespace Zef\Framework\Config {
    // Compatibility sub-aggregate retained for explicit legacy requires.
    // Public declarations moved to per-type files: class SecretValue
    // (SecretValue.php), interface SecretProviderInterface
    // (SecretProviderInterface.php), class EnvironmentSecretProvider
    // (EnvironmentSecretProvider.php).
}

namespace {
    require_once __DIR__ . '/SecretValue.php';
    require_once __DIR__ . '/SecretProviderInterface.php';
    require_once __DIR__ . '/EnvironmentSecretProvider.php';
}
