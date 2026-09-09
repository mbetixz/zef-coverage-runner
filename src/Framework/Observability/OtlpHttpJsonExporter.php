<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {

    final class OtlpHttpJsonExporter implements SpanExporterInterface, MetricExporterInterface, LogExporterInterface
    {
        /** @param array<string,mixed> $resource */
        public function __construct(private readonly string $endpoint, private readonly array $resource, private readonly int $timeoutMs = 500)
        {
            if ($timeoutMs < 1 || $timeoutMs > 10000) {
                throw new \InvalidArgumentException('timeoutMs is outside its allowed range.');
            }
        }
        #[\Override] public function export(array $spans): void
        {
            if ($spans === []) {
                return;
            }
            $url = rtrim($this->endpoint, '/');
            if (!str_ends_with($url, '/v1/traces')) {
                $url .= '/v1/traces';
            }
            $payload = ['resourceSpans' => [['resource' => ['attributes' => $this->attributes($this->resource)],'scopeSpans' => [['scope' => ['name' => 'zef-observability'],'spans' => array_map($this->span(...), $spans)]]]]];
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $context = stream_context_create(['http' => ['method' => 'POST','header' => "Content-Type: application/json\r\nAccept: application/json\r\nContent-Length: " . strlen($json) . "\r\n",'content' => $json,'timeout' => max(.01, $this->timeoutMs / 1000),'ignore_errors' => true]]);
            $result = @file_get_contents($url, false, $context);
            $status = 0;
            // $http_response_header is only populated by PHP when the HTTP
            // layer responded; a refused connection leaves it undefined
            // (runtime-confirmed warning). phpstan models it as always-set.
            // @phpstan-ignore nullCoalesce.variable
            foreach (($http_response_header ?? []) as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m) === 1) {
                    $status = (int) $m[1];
                    break;
                }
            }
            if ($result === false) {
                throw new \RuntimeException('OTLP exporter transport failure.');
            }
            if ($status >= 400 && $status < 500) {
                throw new \InvalidArgumentException('OTLP exporter permanent rejection.');
            }
            if ($status >= 500) {
                throw new \RuntimeException('OTLP exporter transient rejection.');
            }
        }
        /** @return array<string,mixed> */
        private function span(SpanData $span): array
        {
            $out = ['traceId' => $span->context->traceId,'spanId' => $span->context->spanId,'name' => $span->name,'kind' => 'SPAN_KIND_SERVER','startTimeUnixNano' => (string) $span->startUnixNano,'endTimeUnixNano' => (string) $span->endUnixNano,'attributes' => $this->attributes($span->attributes),'status' => ['code' => match($span->status) {
                'OK' => 'STATUS_CODE_OK','ERROR' => 'STATUS_CODE_ERROR',default => 'STATUS_CODE_UNSET'
            }]];
            if ($span->parent?->isValid()) {
                $out['parentSpanId'] = $span->parent->spanId;
            }
            if ($span->statusDescription !== null) {
                $out['status']['message'] = TelemetrySanitizer::string($span->statusDescription, 1024);
            }
            if ($span->events !== []) {
                $out['events'] = array_map(
                    static fn (array $event): array => [
                        'name' => TelemetrySanitizer::string($event['name'], 256),
                        'time_unix_nano' => $event['time_unix_nano'],
                        'attributes' => TelemetrySanitizer::attributes($event['attributes']),
                    ],
                    $span->events,
                );
            }
            return $out;
        }
        /**
         * @param array<string,mixed> $attributes
         * @return list<array{key:string,value:array<string,mixed>}>
         */
        private function attributes(array $attributes): array
        {
            $out = [];
            foreach ($attributes as $key => $value) {
                $out[] = ['key' => $key,'value' => $this->anyValue($value)];
            } return $out;
        }
        /** @return array<string,mixed> */
        private function anyValue(mixed $value): array
        {
            if (is_bool($value)) {
                return ['boolValue' => $value];
            }
            if (is_int($value)) {
                return ['intValue' => (string) $value];
            }
            if (is_float($value)) {
                return ['doubleValue' => $value];
            }
            if (is_array($value)) {
                return ['arrayValue' => ['values' => array_map($this->anyValue(...), array_values($value))]];
            }
            return ['stringValue' => TelemetrySanitizer::string(is_string($value) ? $value : get_debug_type($value))];
        }
        #[\Override] public function exportMetrics(array $metrics): void
        {
            if ($metrics === []) {
                return;
            }
            $url = rtrim($this->endpoint, '/');
            if (!str_ends_with($url, '/v1/metrics')) {
                $url .= '/v1/metrics';
            }
            $list = [];
            foreach ($metrics as $name => $metric) {
                $list[] = ['name' => $name,'sum' => ['aggregationTemporality' => 2,'isMonotonic' => false,'dataPoints' => [['asDouble' => (float) $metric['sum'],'timeUnixNano' => (string) TelemetryClock::nowUnixNano(),'attributes' => $this->attributes($metric['attributes'])]]]];
            }
            $this->postJson($url, ['resourceMetrics' => [['resource' => ['attributes' => $this->attributes($this->resource)],'scopeMetrics' => [['scope' => ['name' => 'zef-observability'],'metrics' => $list]]]]]);
        }
        #[\Override] public function exportLogs(array $records): void
        {
            if ($records === []) {
                return;
            }
            $url = rtrim($this->endpoint, '/');
            if (!str_ends_with($url, '/v1/logs')) {
                $url .= '/v1/logs';
            }
            $list = [];
            foreach ($records as $record) {
                $list[] = ['timeUnixNano' => (string) $record->timeUnixNano,'severityText' => $record->severity,'body' => ['stringValue' => $record->body],'attributes' => $this->attributes($record->attributes)];
            }
            $this->postJson($url, ['resourceLogs' => [['resource' => ['attributes' => $this->attributes($this->resource)],'scopeLogs' => [['scope' => ['name' => 'zef-observability'],'logRecords' => $list]]]]]);
        }
        /** @param array<string,mixed> $payload */
        private function postJson(string $url, array $payload): void
        {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $context = stream_context_create(['http' => ['method' => 'POST','header' => "Content-Type: application/json\r\nAccept: application/json\r\nContent-Length: " . strlen($json) . "\r\n",'content' => $json,'timeout' => max(.01, $this->timeoutMs / 1000),'ignore_errors' => true]]);
            $result = @file_get_contents($url, false, $context);
            $status = 0;
            // $http_response_header is only populated by PHP when the HTTP
            // layer responded; a refused connection leaves it undefined
            // (runtime-confirmed warning). phpstan models it as always-set.
            // @phpstan-ignore nullCoalesce.variable
            foreach (($http_response_header ?? []) as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m) === 1) {
                    $status = (int) $m[1];
                    break;
                }
            }
            if ($result === false) {
                throw new \RuntimeException('OTLP exporter transport failure.');
            }
            if ($status >= 400 && $status < 500) {
                throw new \InvalidArgumentException('OTLP exporter permanent rejection.');
            }
            if ($status >= 500) {
                throw new \RuntimeException('OTLP exporter transient rejection.');
            }
        }
        #[\Override] public function shutdown(): void
        {
        }
    }
}
