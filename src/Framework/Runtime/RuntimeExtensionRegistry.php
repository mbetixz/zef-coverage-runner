<?php

declare(strict_types=1);

namespace Zef\Framework\Runtime {
    final class RuntimeExtensionRegistry
    {
        /** @var array<string, RuntimeExtensionInterface> */
        private array $extensions = [];
        /** @var list<RuntimeExtensionInterface> */
        private array $started = [];
        private ?RuntimeIdentity $identity = null;
        private RuntimeExtensionState $state = RuntimeExtensionState::STOPPED;
        private bool $startedOnce = false;

        public function register(RuntimeExtensionInterface $extension): void
        {
            if ($this->startedOnce) {
                throw new \LogicException('Runtime extensions cannot be registered after the registry has started.');
            }
            $name = trim($extension->name());
            if ($name === '') {
                throw new \InvalidArgumentException('Runtime extension name must not be empty.');
            }
            if (isset($this->extensions[$name])) {
                throw new \InvalidArgumentException("Runtime extension '{$name}' is already registered.");
            }
            $this->extensions[$name] = $extension;
        }

        public function start(RuntimeIdentity $identity): void
        {
            if ($this->startedOnce) {
                throw new \LogicException('Runtime extension registry cannot be started more than once.');
            }
            $this->startedOnce = true;
            $this->identity = $identity;
            $this->state = RuntimeExtensionState::STARTING;

            try {
                foreach ($this->extensions as $extension) {
                    $extension->start($this->context(RuntimeExtensionState::STARTING));
                    $this->started[] = $extension;
                }
            } catch (\Throwable $e) {
                $this->rollbackStartedExtensions();
                $this->state = RuntimeExtensionState::STOPPED;
                throw $e;
            }
        }

        public function ready(): void
        {
            $this->requireStartedState(RuntimeExtensionState::STARTING);
            $this->state = RuntimeExtensionState::READY;
            foreach ($this->started as $extension) {
                $extension->ready($this->context(RuntimeExtensionState::READY));
            }
        }

        public function draining(): void
        {
            $this->requireStartedState(RuntimeExtensionState::STARTING, RuntimeExtensionState::READY);
            $this->state = RuntimeExtensionState::DRAINING;
            foreach (array_reverse($this->started) as $extension) {
                $extension->draining($this->context(RuntimeExtensionState::DRAINING));
            }
        }

        public function stop(): void
        {
            if (!$this->startedOnce || $this->state === RuntimeExtensionState::STOPPED) {
                return;
            }
            if ($this->state !== RuntimeExtensionState::DRAINING) {
                $this->state = RuntimeExtensionState::DRAINING;
            }
            $errors = [];
            foreach (array_reverse($this->started) as $extension) {
                try {
                    $extension->stop($this->context(RuntimeExtensionState::STOPPED));
                } catch (\Throwable $e) {
                    $errors[] = $e;
                }
            }
            $this->state = RuntimeExtensionState::STOPPED;
            $this->started = [];
            if ($errors !== []) {
                throw new \RuntimeException(
                    'One or more runtime extensions failed during stop.',
                    previous: $errors[0],
                );
            }
        }

        public function state(): RuntimeExtensionState
        {
            return $this->state;
        }

        public function identity(): ?RuntimeIdentity
        {
            return $this->identity;
        }

        /** @return list<string> */
        public function registeredNames(): array
        {
            return array_keys($this->extensions);
        }

        private function requireStartedState(RuntimeExtensionState ...$allowed): void
        {
            if (!$this->startedOnce || $this->identity === null) {
                throw new \LogicException('Runtime extension registry has not been started.');
            }
            if (!in_array($this->state, $allowed, true)) {
                throw new \LogicException(sprintf('Illegal runtime extension transition from %s.', $this->state->value));
            }
        }

        private function context(RuntimeExtensionState $state): RuntimeExtensionContext
        {
            if ($this->identity === null) {
                throw new \LogicException('Runtime identity is unavailable.');
            }
            return new RuntimeExtensionContext($this->identity, $state);
        }

        private function rollbackStartedExtensions(): void
        {
            foreach (array_reverse($this->started) as $extension) {
                try {
                    $extension->stop($this->context(RuntimeExtensionState::STOPPED));
                } catch (\Throwable) {
                    // Preserve the original startup failure; rollback remains best-effort.
                }
            }
            $this->started = [];
        }
    }
}
