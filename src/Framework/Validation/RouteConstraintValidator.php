<?php

declare(strict_types=1);

namespace Zef\Framework\Validation {

    use Zef\Framework\Exception\InvalidConfigurationException;
    use Zef\Framework\Exception\RouteConstraintException;

    final class RouteConstraintValidator
    {
        private const array BUILT_IN = [
            'int' => '/^\d+$/',
            'uint' => '/^[1-9]\d*$/',
            'alpha' => '/^[a-zA-Z]+$/',
            'slug' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'uuid' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            'hex' => '/^[0-9a-f]+$/i',
        ];

        /** @var array<string, string> */
        private array $custom = [];
        /** @var array<string, callable(string): bool> */
        private array $compiled = [];

        public function addCustom(string $name, string $regex): void
        {
            if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new InvalidConfigurationException("Invalid route constraint name '{$name}'.");
            }
            if ($regex === '' || strlen($regex) > 2048) {
                throw new InvalidConfigurationException("Invalid route constraint regex '{$name}': pattern length must be between 1 and 2048 bytes.");
            }
            if (preg_match('/\((?:\?:|\?>|\?<[^>]+>)?[^)]*[+*][^)]*\)[+*](?:[?+])?/', $regex) === 1) {
                throw new InvalidConfigurationException("Invalid route constraint regex '{$name}': nested quantified groups are not permitted by the ReDoS safety policy.");
            }
            $previous = set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
            try {
                try {
                    $result = preg_match($regex, '');
                } catch (\ValueError $e) {
                    throw new InvalidConfigurationException(
                        "Invalid route constraint regex '{$name}': {$e->getMessage()}",
                        0,
                        $e,
                    );
                }
            } catch (\ErrorException $e) {
                throw new InvalidConfigurationException(
                    "Invalid route constraint regex '{$name}': {$e->getMessage()}",
                    0,
                    $e,
                );
            } finally {
                restore_error_handler();
            }

            if ($result === false) {
                throw new InvalidConfigurationException(
                    "Invalid route constraint regex '{$name}': " . preg_last_error_msg(),
                );
            }
            $this->custom[$name] = $regex;
            unset($this->compiled[$name]);
        }

        public function test(string $param, string $type, string $value): bool
        {
            $this->assertKnown($type);

            $matcher = $this->compiled[$type] ??= $this->compile($type);

            try {
                return $matcher($value);
            } catch (InvalidConfigurationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new InvalidConfigurationException(
                    "Route constraint '{$type}' failed during evaluation: {$e->getMessage()}",
                    0,
                    $e,
                );
            }
        }

        /**
         * Compile a route constraint once per router/configuration lifecycle.
         * Built-ins use allocation-light native predicates where equivalent;
         * custom constraints are validated once and then represented by a
         * stable matcher closure. This removes repeated constraint lookup and
         * regex resolution from the hot routing path.
         *
         * @return callable(string): bool
         */
        private function compile(string $type): callable
        {
            if (isset(self::BUILT_IN[$type])) {
                return $this->builtInMatcher($type);
            }

            $regex = $this->custom[$type];

            // Pattern validity was established during registration. Capturing
            // the immutable pattern here keeps the hot path free of map lookup.
            return static function (string $value) use ($regex, $type): bool {
                $matched = preg_match($regex, $value);
                if ($matched === false) {
                    throw new InvalidConfigurationException(
                        "Route constraint '{$type}' failed during evaluation: " . preg_last_error_msg(),
                    );
                }
                return $matched === 1;
            };
        }

        /**
         * @return callable(string): bool
         */
        private function builtInMatcher(string $type): callable
        {
            return match ($type) {
                'int' => static fn (string $value): bool => $value !== '' && preg_match('/^\d+$/D', $value) === 1,
                'uint' => static fn (string $value): bool => $value !== '' && $value[0] !== '0' && preg_match('/^\d+$/D', $value) === 1,
                'alpha' => static fn (string $value): bool => $value !== '' && preg_match('/^[a-zA-Z]+$/D', $value) === 1,
                'slug' => static fn (string $value): bool => $value !== '' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value) === 1,
                'uuid' => static fn (string $value): bool => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $value) === 1,
                'hex' => static fn (string $value): bool => $value !== '' && preg_match('/^[0-9a-f]+$/Di', $value) === 1,
                default => throw new InvalidConfigurationException("Unknown route constraint type '{$type}'."),
            };
        }

        public function assertKnown(string $type): void
        {
            if (!isset($this->custom[$type]) && !isset(self::BUILT_IN[$type])) {
                throw new InvalidConfigurationException("Unknown route constraint type '{$type}'.");
            }
        }

        public function assert(string $param, string $type, string $value): void
        {
            if (!$this->test($param, $type, $value)) {
                throw new RouteConstraintException($param, $type, $value);
            }
        }
    }
}
