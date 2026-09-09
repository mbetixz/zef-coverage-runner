<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {

    final class CounterMeter implements MeterInterface
    {
        private const int MAX_SERIES = 1024;

        /** @var array<string,array{count:int|float,sum:float,attributes:array<string,mixed>}> */ private array $data = [];
        #[\Override] public function increment(string $name, int|float $value = 1, array $attributes = []): void
        {
            $normalized = $this->normalizeAttributes($name, $attributes);
            $key = self::key($name, $normalized);
            if (!isset($this->data[$key]) && count($this->data) >= self::MAX_SERIES) {
                $overflow = ['zef.cardinality.bucket' => 'overflow'];
                $key = self::key($name, $overflow);
                if (!isset($this->data[$key])) {
                    unset($this->data[array_key_last($this->data)]);
                }
                $normalized = $overflow;
            }
            $this->data[$key] ??= ['count' => 0,'sum' => 0.0,'attributes' => $normalized];
            $this->data[$key]['count'] += $value;
            $this->data[$key]['sum'] += (float) $value;
        }
        #[\Override] public function observe(string $name, float $value, array $attributes = []): void
        {
            $normalized = $this->normalizeAttributes($name, $attributes);
            $key = self::key($name, $normalized);
            if (!isset($this->data[$key]) && count($this->data) >= self::MAX_SERIES) {
                $overflow = ['zef.cardinality.bucket' => 'overflow'];
                $key = self::key($name, $overflow);
                if (!isset($this->data[$key])) {
                    unset($this->data[array_key_last($this->data)]);
                }
                $normalized = $overflow;
            }
            $this->data[$key] ??= ['count' => 0,'sum' => 0.0,'attributes' => $normalized];
            $this->data[$key]['count'] += 1;
            $this->data[$key]['sum'] += $value;
        }
        #[\Override] public function snapshot(): array
        {
            return $this->data;
        }
        /**
         * @param array<string,mixed> $attributes
         * @return array<string,mixed>
         */
        private function normalizeAttributes(string $name, array $attributes): array
        {
            $clean = TelemetrySanitizer::attributes($attributes);
            if (str_starts_with($name, 'zef.http.') || str_starts_with($name, 'zef.container.')) {
                /** @var array<string,mixed> $allowed */
                $allowed = [];
                foreach (['http.request.method','http.response.status_code'] as $dimension) {
                    if (array_key_exists($dimension, $clean)) {
                        $allowed[$dimension] = $clean[$dimension];
                    }
                }
                if ($name === 'zef.http.errors.total' && array_key_exists('exception.type', $clean)) {
                    $exceptionType = $clean['exception.type'];
                    if (is_string($exceptionType)) {
                        $allowed['exception.type'] = TelemetrySanitizer::string($exceptionType, 128);
                    }
                }
                if ($name === 'zef.container.resolve.duration_seconds' && array_key_exists('zef.service.id', $clean)) {
                    $service = $clean['zef.service.id'];
                    if (is_string($service)) {
                        $allowed['zef.service.id'] = self::boundedServiceDimension($service);
                    }
                }
                $clean = $allowed;
            } elseif ($name === 'zef.lifecycle.events.total') {
                /** @var array<string,mixed> $allowed */
                $allowed = [];
                if (array_key_exists('event.name', $clean)) {
                    $eventValue = $clean['event.name'];
                    $event = is_string($eventValue) ? $eventValue : '';
                    $allowed['event.name'] = in_array($event, [
                        'worker.started','worker.ready','request.started','request.completed',
                        'request.failed','worker.recovery.detected','worker.terminated',
                        'telemetry.flush','telemetry.shutdown',
                    ], true) ? $event : 'other';
                }
                $clean = $allowed;
            } else {
                // Custom metric families remain bounded by MAX_SERIES; framework-owned
                // high-entropy HTTP/container dimensions are normalized above.
            }
            ksort($clean);
            return $clean;
        }
        private static function boundedServiceDimension(string $value): string
        {
            return preg_match('/^[a-z0-9._:-]{1,96}$/i', $value) === 1 ? $value : '[other]';
        }
        /** @param array<string,mixed> $attributes */
        private static function key(string $name, array $attributes): string
        {
            return $name . '|' . json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
    }

}
