<?php

declare(strict_types=1);

namespace Zef\Framework\Resource;

interface AdmissionControllerInterface
{
    public function admit(): AdmissionDecision;
    public function complete(): void;
    public function queue(): AdmissionDecision;
    public function dequeue(): void;
    public function snapshot(): AdmissionSnapshot;
}
