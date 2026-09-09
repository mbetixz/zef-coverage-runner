<?php

declare(strict_types=1);

namespace Zef\Framework\CQRS; // // Compatibility aggregate retained for explicit legacy requires.

require_once __DIR__ . '/CqrsContext.php';
require_once __DIR__ . '/CommandHandlerInterface.php';
require_once __DIR__ . '/QueryHandlerInterface.php';
require_once __DIR__ . '/CqrsMiddlewareInterface.php';
require_once __DIR__ . '/CqrsEventResult.php';
require_once __DIR__ . '/IdempotencyStoreInterface.php';
require_once __DIR__ . '/InMemoryIdempotencyStore.php';
require_once __DIR__ . '/CommandBusInterface.php';
require_once __DIR__ . '/QueryBusInterface.php';
require_once __DIR__ . '/CqrsHandlerNotFoundException.php';
require_once __DIR__ . '/CqrsHandlerConflictException.php';
require_once __DIR__ . '/CommandBus.php';
require_once __DIR__ . '/QueryBus.php';
