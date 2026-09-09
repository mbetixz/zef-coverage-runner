<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.5.0-beta1 — Distributed security aggregate forwarder (Fase C3 / RM-09).
 *
 * Structural decomposition: each distributed-security type (enum / readonly
 * class / interface / implementation) now lives in its own file under the
 * Distributed/ subdirectory (one type per file). This aggregate is kept ONLY
 * as a compatibility shim for consumers that require this path directly. The
 * canonical loader is the per-class map in src/autoload.php; the per-type
 * files are loaded from the Distributed/ subdirectory.
 *
 * Type → file mapping (namespace Zef\Framework\Security\Distributed):
 *   AuthenticationStatus            → Distributed/AuthenticationStatus.php
 *   ReplayDecision                  → Distributed/ReplayDecision.php
 *   SecurityVerdict                 → Distributed/SecurityVerdict.php
 *   SecurityFailure                 → Distributed/SecurityFailure.php
 *   CredentialHandle                → Distributed/CredentialHandle.php
 *   SecurityContext                 → Distributed/SecurityContext.php
 *   SecurityRequest                 → Distributed/SecurityRequest.php
 *   AuthenticationResult            → Distributed/AuthenticationResult.php
 *   AuthorizationResult             → Distributed/AuthorizationResult.php
 *   ReplayResult                    → Distributed/ReplayResult.php
 *   SecurityAdmissionDecision       → Distributed/SecurityAdmissionDecision.php
 *   CredentialProviderInterface     → Distributed/CredentialProviderInterface.php
 *   AuthorizationPolicyInterface    → Distributed/AuthorizationPolicyInterface.php
 *   ReplayProtectorInterface        → Distributed/ReplayProtectorInterface.php
 *   SecurityBoundaryInterface       → Distributed/SecurityBoundaryInterface.php
 *   BoundedInMemoryReplayProtector  → Distributed/BoundedInMemoryReplayProtector.php
 *   DefaultSecurityBoundary         → Distributed/DefaultSecurityBoundary.php
 */

require_once __DIR__ . '/Distributed/AuthenticationStatus.php';
require_once __DIR__ . '/Distributed/ReplayDecision.php';
require_once __DIR__ . '/Distributed/SecurityVerdict.php';
require_once __DIR__ . '/Distributed/SecurityFailure.php';
require_once __DIR__ . '/Distributed/CredentialHandle.php';
require_once __DIR__ . '/Distributed/SecurityContext.php';
require_once __DIR__ . '/Distributed/SecurityRequest.php';
require_once __DIR__ . '/Distributed/AuthenticationResult.php';
require_once __DIR__ . '/Distributed/AuthorizationResult.php';
require_once __DIR__ . '/Distributed/ReplayResult.php';
require_once __DIR__ . '/Distributed/SecurityAdmissionDecision.php';
require_once __DIR__ . '/Distributed/CredentialProviderInterface.php';
require_once __DIR__ . '/Distributed/AuthorizationPolicyInterface.php';
require_once __DIR__ . '/Distributed/ReplayProtectorInterface.php';
require_once __DIR__ . '/Distributed/SecurityBoundaryInterface.php';
require_once __DIR__ . '/Distributed/BoundedInMemoryReplayProtector.php';
require_once __DIR__ . '/Distributed/DefaultSecurityBoundary.php';
require_once __DIR__ . '/Distributed/StaticCredentialProvider.php';
require_once __DIR__ . '/Distributed/AllowScopeAuthorizationPolicy.php';
