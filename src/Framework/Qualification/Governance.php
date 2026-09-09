<?php

declare(strict_types=1);

namespace Zef\Framework\Qualification;

// Compatibility aggregate retained for explicit legacy requires.
// Public declarations moved to per-type files (D2 structural decomposition):
// enum QualificationStatus, class QualificationGate, class QualificationEvidence,
// class QualificationGateResult, class QualificationLedger.
// W6 surface retained per-type: sha256 evidence regex ^[a-f0-9]{64}$;
// 'A mandatory PASS gate requires evidence.'; guard
// status !== QualificationStatus::PASS (waiver is not promotion).
require_once __DIR__ . '/QualificationStatus.php';
require_once __DIR__ . '/QualificationGate.php';
require_once __DIR__ . '/QualificationEvidence.php';
require_once __DIR__ . '/QualificationGateResult.php';
require_once __DIR__ . '/QualificationLedger.php';
