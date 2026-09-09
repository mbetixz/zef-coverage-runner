<?php

declare(strict_types=1);

namespace Zef\Framework\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

final class EventDispatcher implements EventBusInterface, ListenerProviderInterface
{
    /** @var array<string,list<EventRegistration>> */ private array $listeners = [];
    /** @var array<string,list<EventRegistration>> */ private array $resolved = [];
    private int $sequence = 0;
    private bool $frozen = false;

    #[\Override]
    public function listen(string $eventClass, callable $listener, int $priority = 0): void
    {
        if ($this->frozen) {
            throw new \LogicException('Cannot register event listeners after the event bus is frozen.');
        }
        if ($eventClass === '' || (!class_exists($eventClass) && !interface_exists($eventClass))) {
            throw new \InvalidArgumentException("Unknown event class '{$eventClass}'.");
        }
        $this->listeners[$eventClass][] = new EventRegistration($eventClass, $listener, $priority, $this->sequence++);
        $this->resolved = [];
    }

    #[\Override]
    public function subscribe(EventSubscriberInterface $subscriber): void
    {
        foreach ($subscriber::subscriptions() as $eventClass => $handlers) {
            foreach ($handlers as $handler) {
                $priority = 0;
                $listener = $handler;
                if (is_array($handler) && is_int($handler[0]) && is_callable($handler[1])) {
                    $priority = $handler[0];
                    $listener = $handler[1];
                }
                if (!is_callable($listener)) {
                    throw new \InvalidArgumentException('Event subscriber listener must be callable.');
                }
                $this->listen($eventClass, $listener, $priority);
            }
        }
    }

    #[\Override] public function freeze(): void
    {
        if ($this->frozen) {
            return;
        }
        foreach ($this->listeners as &$registrations) {
            usort($registrations, static fn (EventRegistration $a, EventRegistration $b): int => $b->priority <=> $a->priority ?: $a->sequence <=> $b->sequence);
        }
        unset($registrations);
        $this->frozen = true;
        $this->resolved = [];
    }

    #[\Override] public function dispatch(object $event): object
    {
        return $this->dispatchWithContext($event, new EventContext(bin2hex(random_bytes(16)), (int) (microtime(true) * 1_000_000_000)));
    }

    #[\Override] public function dispatchWithContext(object $event, EventContext $context): object
    {
        $errors = [];
        foreach ($this->getListenersForEvent($event) as $registration) {
            try {
                $listener = $registration->listener;
                if (!is_callable($listener)) {
                    throw new \LogicException('Registered event listener is no longer callable.');
                }
                if ($registration->acceptsContext) {
                    $listener($event, $context);
                } else {
                    $listener($event);
                }
            } catch (\Throwable $e) {
                $errors[] = $e;
                break;
            }
        }
        if ($errors !== []) {
            throw new EventDispatchException('Event listener failed for ' . $event::class . '.', $errors, $event);
        }
        return $event;
    }

    /** @return list<EventRegistration> */
    #[\Override] public function getListenersForEvent(object $event): iterable
    {
        $key = $event::class;
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }
        $result = [];
        foreach ($this->listeners as $eventClass => $registrations) {
            if (is_a($event, $eventClass)) {
                foreach ($registrations as $registration) {
                    $result[] = $registration;
                }
            }
        }
        usort($result, static fn (EventRegistration $a, EventRegistration $b): int => $b->priority <=> $a->priority ?: $a->sequence <=> $b->sequence);
        if ($this->frozen) {
            $this->resolved[$key] = $result;
        }
        return $result;
    }

    /** @return list<EventRegistration> */
    #[\Override]
    public function registrations(): array
    {
        $out = [];
        foreach ($this->listeners as $rs) {
            foreach ($rs as $r) {
                $out[] = $r;
            }
        } return $out;
    }
    public function isFrozen(): bool
    {
        return $this->frozen;
    }
}
