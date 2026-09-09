<?php

declare(strict_types=1);

namespace Zef\Framework\Event;

interface EventSubscriberInterface
{
    /** @return array<string,list<callable|array{int,callable}>> */
    public static function subscriptions(): array;
}
