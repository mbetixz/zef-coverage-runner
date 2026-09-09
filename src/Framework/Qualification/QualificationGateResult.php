<?php

declare(strict_types=1);

namespace Zef\Framework\Qualification;

/**
 * Immutable outcome of evaluating one qualification gate.
 *
 * gate is the gate evaluated; status is its final qualification state; note
 * is an optional human-readable explanation bounded to 512 bytes. A PASS on
 * a mandatory gate requires at least one evidence entry (validated in the
 * constructor). Evidence is accepted as an untyped array and validated
 * element-wise at runtime: each element must be a QualificationEvidence
 * object, otherwise an InvalidArgumentException is thrown.
 *
 * @throws \InvalidArgumentException on non-evidence element, over-long note,
 *         or a mandatory PASS gate without evidence.
 */
final readonly class QualificationGateResult
{/** @param array<int,mixed> $evidence runtime-validated: each element must be a QualificationEvidence */public function __construct(public QualificationGate $gate, public QualificationStatus $status, public array $evidence = [], public string $note = '')
{
    foreach ($evidence as $item) {
        if (!$item instanceof QualificationEvidence) {
            throw new \InvalidArgumentException('Gate evidence must contain QualificationEvidence objects.');
        }
    }if (strlen($note) > 512) {
        throw new \InvalidArgumentException('Gate note is too long.');
    }if ($status === QualificationStatus::PASS && $gate->mandatory && $evidence === []) {
        throw new \InvalidArgumentException('A mandatory PASS gate requires evidence.');
    }
}
}
