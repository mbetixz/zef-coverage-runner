<?php

declare(strict_types=1);

namespace Zef\Framework\Qualification;

final readonly class QualificationGate
{
    public function __construct(public string $id, public string $name, public bool $mandatory = true)
    {
        self::b($id, 32, 'Gate id');
        self::b($name, 128, 'Gate name');
    }private static function b(string $v, int $m, string $l): void
    {
        if (strlen($v) < 1 || strlen($v) > $m) {
            throw new \InvalidArgumentException("{$l} is invalid.");
        }
    }
}
