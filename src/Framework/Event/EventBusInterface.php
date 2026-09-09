<?php

declare(strict_types=1);

namespace Zef\Framework\Event;

use Psr\EventDispatcher\EventDispatcherInterface;

interface EventBusInterface extends EventDispatcherInterface
{
    public function listen(string $eventClass, callable $listener, int $priority = 0): void;
    public function subscribe(EventSubscriberInterface $subscriber): void;
    public function dispatchWithContext(object $event, EventContext $context): object;
    /** @return list<EventRegistration> */
    public function registrations(): array;
    public function freeze(): void;
}
