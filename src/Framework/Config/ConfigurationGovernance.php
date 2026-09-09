<?php

declare(strict_types=1);

namespace Zef\Framework\Config {

    final class ConfigurationGovernance
    {
        /** @var array<string,callable(array<string,mixed>):void> */
        private array $validators = [];
        private ?ConfigurationSnapshot $snapshot = null;

        public function addValidator(string $name, callable $validator): void
        {
            if ($this->snapshot !== null) {
                throw new \LogicException('Validators cannot change after publication.');
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,127}$/', $name) !== 1) {
                throw new \InvalidArgumentException('Invalid validator name.');
            }
            $this->validators[$name] = $validator;
        }

        /** @param array<string,mixed> $values */
        public function publish(array $values, int $version): ConfigurationSnapshot
        {
            foreach ($this->validators as $name => $validator) {
                try {
                    $validator($values);
                } catch (\Throwable $e) {
                    throw new \InvalidArgumentException("Configuration validation failed: {$name}", 0, $e);
                }
            }
            return $this->snapshot = new ConfigurationSnapshot($values, $version);
        }

        public function current(): ?ConfigurationSnapshot
        {
            return $this->snapshot;
        }
    }

}
