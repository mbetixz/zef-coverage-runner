<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.5.0-beta1 — Security aggregate forwarder (Fase C2 / RM-09).
 *
 * Structural decomposition: each security type now lives in its own file
 * (one class per file). This aggregate is kept ONLY as a compatibility shim
 * for consumers that require this path directly. The canonical loader is the
 * per-class map in src/autoload.php; the monolith builder appends the nine
 * per-class files directly.
 *
 * Class → file mapping:
 *   SecurityPolicy            → SecurityPolicy.php
 *   SecurityContext           → SecurityContext.php
 *   RateLimitDecision         → RateLimitDecision.php
 *   RateLimiterInterface      → RateLimiterInterface.php
 *   InMemoryRateLimiter       → InMemoryRateLimiter.php
 *   ClientAddressResolver     → ClientAddressResolver.php
 *   OriginPolicy              → OriginPolicy.php
 *   CsrfTokenManager          → CsrfTokenManager.php
 *   SecurityRuntimeMiddleware → SecurityRuntimeMiddleware.php
 */

require_once __DIR__ . '/SecurityPolicy.php';
require_once __DIR__ . '/SecurityContext.php';
require_once __DIR__ . '/RateLimitDecision.php';
require_once __DIR__ . '/RateLimiterInterface.php';
require_once __DIR__ . '/InMemoryRateLimiter.php';
require_once __DIR__ . '/ApcuRateLimiter.php';
require_once __DIR__ . '/RedisRateLimiter.php';
require_once __DIR__ . '/SharedRateLimitStoreInterface.php';
require_once __DIR__ . '/RedisSharedRateLimitStore.php';
require_once __DIR__ . '/ClientAddressResolver.php';
require_once __DIR__ . '/OriginPolicy.php';
require_once __DIR__ . '/CsrfTokenManager.php';
require_once __DIR__ . '/SecurityRuntimeMiddleware.php';
require_once __DIR__ . '/AuthenticationMiddleware.php';
