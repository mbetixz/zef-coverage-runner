<?php

declare(strict_types=1);

namespace Zef\Framework\Router {

    use Zef\Framework\Validation\RouteConstraintValidator;

    /**
     * Compiled radix tree builder and traverser extracted from Router
     * (C4 / RM-09, non-line-based). Owns the node layout contract:
     *
     *   static   => map<string,int>
     *   dynamic  => map<string,int> where key is constraint name or '' for unconstrained
     *   routes   => sorted route indexes terminating at this node
     *
     * Constraint validation stays in the traversers so the framework preserves
     * the existing 400 semantics for structurally matching but
     * constraint-invalid paths.
     */
    final class RadixIndex
    {
        public function __construct(
            private readonly RoutePatternParser $parser,
        ) {
        }

        /**
         * Build a fresh radix index from the given routes.
         *
         * @param list<array{method:string,pattern:string,handler:string,module:?string,priority:int,sequence:int,segments:list<array{dynamic:bool,name?:string,constraint?:string|null,value?:string}>,signature:string,staticCount:int,constrainedCount:int}> $routes
         *
         * @return list<array{
         *   static:array<string,int>,
         *   dynamic:array<string,int>,
         *   routes:list<int>
         * }>
         */
        public function compile(array $routes): array
        {
            /** @var list<array{static:array<string,int>,dynamic:array<string,int>,routes:list<int>}> $radix */
            $radix = [
                ['static' => [], 'dynamic' => [], 'routes' => []],
            ];

            foreach ($routes as $index => $route) {
                $nodeIndex = 0;

                foreach ($route['segments'] as $segment) {
                    /** @var array{static:array<string,int>,dynamic:array<string,int>,routes:list<int>} $node */
                    $node = &$radix[$nodeIndex];

                    if ($segment['dynamic']) {
                        $edgeKey = $segment['constraint'] ?? '';

                        if (!isset($node['dynamic'][$edgeKey])) {
                            $radix[] = ['static' => [], 'dynamic' => [], 'routes' => []];
                            $node['dynamic'][$edgeKey] = count($radix) - 1;
                        }

                        $nodeIndex = $node['dynamic'][$edgeKey];
                        continue;
                    }

                    if (!is_string($segment['value'] ?? null)) {
                        throw new \LogicException('Static route segment must carry a string value.');
                    }
                    $edgeKey = $segment['value'];

                    if (!isset($node['static'][$edgeKey])) {
                        $radix[] = ['static' => [], 'dynamic' => [], 'routes' => []];
                        $node['static'][$edgeKey] = count($radix) - 1;
                    }

                    $nodeIndex = $node['static'][$edgeKey];
                }

                /** @var array{static:array<string,int>,dynamic:array<string,int>,routes:list<int>} $node */
                $node = &$radix[$nodeIndex];
                $node['routes'][] = $index;
            }

            return $radix;
        }

        /**
         * Constraint-aware radix traversal used by the successful request path.
         * Dynamic edges are pruned as soon as their registered constraint does
         * not accept the corresponding segment value.
         *
         * @param list<array{static:array<string,int>,dynamic:array<string,int>,routes:list<int>}> $radix
         *
         * @return list<int>
         */
        public function matchingCandidates(array $radix, string $path, RouteConstraintValidator $constraints): array
        {
            $parts = $this->parser->splitPath($path);
            $frontier = [0];

            foreach ($parts as $part) {
                $next = [];

                foreach ($frontier as $nodeIndex) {
                    $staticChild = $radix[$nodeIndex]['static'][$part] ?? null;
                    if ($staticChild !== null) {
                        $next[$staticChild] = true;
                    }

                    foreach ($radix[$nodeIndex]['dynamic'] as $constraint => $dynamicChild) {
                        if ($constraint !== '' && !$constraints->test('_', $constraint, $part)) {
                            continue;
                        }
                        $next[$dynamicChild] = true;
                    }
                }

                if ($next === []) {
                    return [];
                }

                $frontier = array_map('intval', array_keys($next));
            }

            $candidates = [];
            foreach ($frontier as $nodeIndex) {
                foreach ($radix[$nodeIndex]['routes'] as $routeIndex) {
                    $candidates[$routeIndex] = true;
                }
            }

            if ($candidates === []) {
                return [];
            }

            $candidates = array_map('intval', array_keys($candidates));
            sort($candidates, SORT_NUMERIC);
            return $candidates;
        }

        /**
         * Structural radix traversal used by the slow/failure path: every
         * structurally compatible route is retained regardless of constraints
         * so constraint failures remain 400-class and method mismatches 405.
         *
         * @param list<array{static:array<string,int>,dynamic:array<string,int>,routes:list<int>}> $radix
         *
         * @return list<int>
         */
        public function structuralCandidates(array $radix, string $path): array
        {
            $parts = $this->parser->splitPath($path);
            $frontier = [0];

            foreach ($parts as $part) {
                $next = [];

                foreach ($frontier as $nodeIndex) {
                    $staticChild = $radix[$nodeIndex]['static'][$part] ?? null;
                    if ($staticChild !== null) {
                        $next[$staticChild] = true;
                    }

                    foreach ($radix[$nodeIndex]['dynamic'] as $dynamicChild) {
                        $next[$dynamicChild] = true;
                    }
                }

                if ($next === []) {
                    return [];
                }

                $frontier = array_map('intval', array_keys($next));
            }

            $candidates = [];

            foreach ($frontier as $nodeIndex) {
                foreach ($radix[$nodeIndex]['routes'] as $routeIndex) {
                    $candidates[$routeIndex] = true;
                }
            }

            if ($candidates === []) {
                return [];
            }

            $candidates = array_map('intval', array_keys($candidates));
            sort($candidates, SORT_NUMERIC);

            return $candidates;
        }
    }
}
