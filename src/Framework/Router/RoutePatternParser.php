<?php

declare(strict_types=1);

namespace Zef\Framework\Router {

    /**
     * Parses route patterns and request paths into the canonical segment shape
     * consumed by Router, RadixIndex and RouteMatcher (C4 / RM-09 extraction,
     * non-line-based). Stateless: all methods are pure functions of input.
     */
    final class RoutePatternParser
    {
        /**
         * @return list<array{dynamic:bool,name?:string,constraint?:string|null,value?:string}>
         */
        public function parse(string $pattern): array
        {
            $parts = explode('/', trim($pattern, '/'));
            if ($pattern === '/') {
                $parts = [];
            }

            return array_map(
                static function (string $segment): array {
                    if (
                        preg_match(
                            '/^\{([A-Za-z_][A-Za-z0-9_]*)(?::([A-Za-z_][A-Za-z0-9_]*))?\}$/',
                            $segment,
                            $matches,
                        ) === 1
                    ) {
                        return [
                            'dynamic' => true,
                            'name' => $matches[1],
                            'constraint' => $matches[2] ?? null,
                        ];
                    }

                    if (str_contains($segment, '{') || str_contains($segment, '}')) {
                        throw new \InvalidArgumentException("Invalid route segment '{$segment}'.");
                    }

                    return [
                        'dynamic' => false,
                        'value' => $segment,
                    ];
                },
                $parts,
            );
        }

        /**
         * @param list<array{dynamic:bool,name?:string,constraint?:string|null,value?:string}> $segments
         */
        public function canonicalSignature(string $method, array $segments): string
        {
            $parts = [];

            foreach ($segments as $segment) {
                $parts[] = $segment['dynamic']
                    ? '*' . ($segment['constraint'] ?? '')
                    : (string) ($segment['value'] ?? '');
            }

            return $method . '|/' . implode('/', $parts);
        }

        /**
         * @return list<string>
         */
        public function splitPath(string $path): array
        {
            if ($path === '/') {
                return [];
            }

            return explode('/', trim($path, '/'));
        }
    }
}
