<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.5.0-beta1 — Validation aggregate forwarder (Fase C1 / RM-09).
 *
 * Structural decomposition: each validator class now lives in its own file
 * (one class per file). This aggregate is kept ONLY as a compatibility shim
 * for consumers that require this path directly. The canonical loader is the
 * per-class map in src/autoload.php; the monolith builder appends the seven
 * per-class files directly.
 *
 * Class → file mapping:
 *   HeaderValidator           → Header.php
 *   HttpStatusValidator       → HttpStatus.php
 *   TrustedHostValidator      → TrustedHost.php
 *   PortRangeValidator        → PortRange.php
 *   HttpMethodValidator       → HttpMethod.php
 *   RouteConstraintValidator  → RouteConstraint.php
 *   DependencyGraphValidator  → DependencyGraph.php
 */

require_once __DIR__ . '/Header.php';
require_once __DIR__ . '/HttpStatus.php';
require_once __DIR__ . '/TrustedHost.php';
require_once __DIR__ . '/PortRange.php';
require_once __DIR__ . '/HttpMethod.php';
require_once __DIR__ . '/RouteConstraint.php';
require_once __DIR__ . '/DependencyGraph.php';
