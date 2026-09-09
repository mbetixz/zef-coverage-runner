<?php

declare(strict_types=1);

namespace Zef\Framework\Event;

final class EventDispatchException extends \RuntimeException
{
    /** @param list<\Throwable> $errors */
    public function __construct(string $message, public readonly array $errors, public readonly object $event)
    {
        parent::__construct($message, 0, $errors[0] ?? null);
    }
}
