<?php

declare(strict_types=1);

namespace Zef\Framework\Observability {
    final class TelemetrySanitizer
    {
        private const array SENSITIVE = ['authorization','cookie','set-cookie','password','passwd','token','secret','api-key','api_key','apikey','access-token','refresh-token','client-secret'];
        public static function isSensitiveKey(string $key): bool
        {
            $normalized = strtolower(str_replace(['_',' '], '-', $key));
            return array_any(self::SENSITIVE, fn ($needle) => $normalized === $needle || str_contains($normalized, $needle));
        }
        public static function string(string $value, int $limit = 2048): string
        {
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
            return strlen($value) > $limit ? substr($value, 0, $limit) . '…' : $value;
        }
        /**
         * Centralized secret redaction for free-form log/error strings.
         * Scrubs common credential patterns (password=..., token=..., api_key=...,
         * Authorization: <value>, Bearer <token>) and truncates to $limit.
         * This is the single sanitizer every logger/error path must use.
         */
        public static function redact(string $value, int $limit = 2048): string
        {
            $value = preg_replace(
                '/(password|passwd|token|secret|api[_-]?key|client[_-]?secret|access[_-]?token|refresh[_-]?token|authorization)\s*[:=]\s*([^\s,;]+)/i',
                '$1=[REDACTED]',
                $value,
            ) ?? $value;
            $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value) ?? $value;
            return self::string($value, $limit);
        }
        public static function value(mixed $value): mixed
        {
            if (is_string($value)) {
                return self::string($value);
            }
            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                return $value;
            }
            if (is_array($value)) {
                $out = [];
                foreach (array_slice($value, 0, 32, true) as $k => $v) {
                    $key = (string) $k;
                    if (self::isSensitiveKey($key)) {
                        $out[$key] = '[REDACTED]';
                        continue;
                    }
                    $out[$key] = self::value($v);
                }
                return $out;
            }
            return get_debug_type($value);
        }
        /**
         * @param array<string,mixed> $attributes
         * @return array<string,mixed>
         */
        public static function attributes(array $attributes): array
        {
            $out = [];
            foreach ($attributes as $key => $value) {
                if (!self::isSensitiveKey($key)) {
                    $out[$key] = self::value($value);
                }
            } return $out;
        }
    }
}

namespace {
    require_once __DIR__ . '/TelemetryClock.php';
}
