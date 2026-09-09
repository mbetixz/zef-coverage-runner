<?php

declare(strict_types=1);

namespace Zef\Framework\Event;

final readonly class EventRegistration
{
    public bool $acceptsContext;
    public function __construct(
        public string $eventClass,
        public mixed $listener,
        public int $priority = 0,
        public int $sequence = 0,
    ) {
        if ($eventClass === '' || (!class_exists($eventClass) && !interface_exists($eventClass))) {
            throw new \InvalidArgumentException("Invalid event class '{$eventClass}'.");
        }
        if (!is_callable($listener)) {
            throw new \InvalidArgumentException('Event listener must be callable.');
        }
        if (is_array($listener)) {
            $reflection = new \ReflectionMethod($listener[0], (string) $listener[1]);
        } elseif ($listener instanceof \Closure) {
            $reflection = new \ReflectionFunction($listener);
        } elseif (is_object($listener) && method_exists($listener, '__invoke')) {
            $reflection = new \ReflectionMethod($listener, '__invoke');
        } else {
            throw new \InvalidArgumentException('Event listener reflection is unsupported.');
        }
        $this->acceptsContext = $reflection->getNumberOfParameters() >= 2;
    }
}
