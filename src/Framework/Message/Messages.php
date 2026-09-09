<?php

declare(strict_types=1);

namespace Zef\Framework\Message; // // Compatibility aggregate retained for explicit legacy requires.

require_once __DIR__ . '/MessageEnvelope.php';
require_once __DIR__ . '/MessageContext.php';
require_once __DIR__ . '/MessageResult.php';
require_once __DIR__ . '/MessageBusInterface.php';
require_once __DIR__ . '/MessageSerializerInterface.php';
require_once __DIR__ . '/MessageHandlerInterface.php';
require_once __DIR__ . '/MessageMiddlewareInterface.php';
require_once __DIR__ . '/MessageTransportInterface.php';
require_once __DIR__ . '/InProcessMessageBus.php';
require_once __DIR__ . '/JsonMessageSerializer.php';
