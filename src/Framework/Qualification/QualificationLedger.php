<?php

declare(strict_types=1);

namespace Zef\Framework\Qualification;

final class QualificationLedger
{
    /** @var array<string,QualificationGateResult> */private array $results = [];
    public function register(QualificationGate $gate): void
    {
        if (isset($this->results[$gate->id])) {
            throw new \LogicException("Qualification gate already registered: {$gate->id}");
        }$this->results[$gate->id] = new QualificationGateResult($gate, QualificationStatus::NOT_STARTED);
    }
    /** @param array<int,mixed> $evidence */public function record(string $gateId, QualificationStatus $status, array $evidence = [], string $note = ''): void
    {
        if (!isset($this->results[$gateId])) {
            throw new \InvalidArgumentException("Unknown qualification gate: {$gateId}");
        }if ($status === QualificationStatus::PASS && $this->results[$gateId]->gate->mandatory && $evidence === []) {
            throw new \InvalidArgumentException('A mandatory PASS gate requires evidence.');
        }$this->results[$gateId] = new QualificationGateResult($this->results[$gateId]->gate, $status, $evidence, $note);
    }
    public function status(): QualificationStatus
    {
        if ($this->results === []) {
            return QualificationStatus::NOT_STARTED;
        }foreach ($this->results as $r) {
            if ($r->status === QualificationStatus::FAIL) {
                return QualificationStatus::FAIL;
            }if ($r->status === QualificationStatus::NOT_STARTED || $r->status === QualificationStatus::IN_PROGRESS) {
                return QualificationStatus::IN_PROGRESS;
            }if ($r->status === QualificationStatus::WAIVED && $r->gate->mandatory) {
                return QualificationStatus::WAIVED;
            }
        }return QualificationStatus::PASS;
    }
    /** @return list<QualificationGateResult> */public function results(): array
    {
        return array_values($this->results);
    }
    public function canPromote(): bool
    {
        if ($this->results === []) {
            return false;
        }
        return array_all($this->results, fn ($r) => !($r->gate->mandatory && $r->status !== QualificationStatus::PASS));
    }
}
