<?php

declare(strict_types=1);

namespace Zef\Framework\Qualification;

final readonly class QualificationEvidence
{
    public function __construct(public string $artifact, public string $sha256, public string $source, public string $summary)
    {
        self::b($artifact, 256, 'Evidence artifact');
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new \InvalidArgumentException('Evidence SHA-256 is invalid.');
        }self::b($source, 128, 'Evidence source');
        self::b($summary, 512, 'Evidence summary');
    }private static function b(string $v, int $m, string $l): void
    {
        if (strlen($v) < 1 || strlen($v) > $m) {
            throw new \InvalidArgumentException("{$l} is invalid.");
        }
    }
}
