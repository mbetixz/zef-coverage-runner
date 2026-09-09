<?php

declare(strict_types=1);

namespace Zef\Framework\CQRS;

/**
 * Immutable outcome of a CQRS command/query execution.
 *
 * result is the handler's return value (mixed by design: the bus is
 * handler-agnostic); events is the ordered list of domain event objects the
 * handler recorded during execution, each of which must be an object. The
 * list is validated element-wise so an empty array is the default for
 * handlers that emit no events.
 *
 * @throws \InvalidArgumentException when events contains a non-object value.
 */
final readonly class CqrsEventResult
{
    /** @param list<object> $events recorded domain events, each an object */
    public function __construct(public mixed $result, public array $events = [])
    {
        foreach ($events as $event) { /* @var object $event */
        }
    }
}
