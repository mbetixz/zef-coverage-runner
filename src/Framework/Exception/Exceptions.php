<?php

declare(strict_types=1);

namespace Zef\Framework\Exception {
    // Compatibility aggregate retained for explicit legacy requires.
}

namespace {
    require_once __DIR__ . '/ServiceNotFoundException.php';
    require_once __DIR__ . '/ServiceResolutionException.php';
    require_once __DIR__ . '/ConcurrentServiceInitializationException.php';
    require_once __DIR__ . '/ServiceCircularDependencyException.php';
    require_once __DIR__ . '/InvalidFactoryException.php';
    require_once __DIR__ . '/CircularAliasException.php';
    require_once __DIR__ . '/InvalidConfigurationException.php';
    require_once __DIR__ . '/ModuleDependencyViolationException.php';
    require_once __DIR__ . '/RouteNotFoundException.php';
    require_once __DIR__ . '/MethodNotAllowedException.php';
    require_once __DIR__ . '/PayloadTooLargeException.php';
    require_once __DIR__ . '/RouteConstraintException.php';
    require_once __DIR__ . '/InvalidHeaderException.php';
}
