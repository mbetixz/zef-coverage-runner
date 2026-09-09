<?php

declare(strict_types=1);

namespace Zef\Framework\Router {

    use Zef\Framework\Exception\RouteConstraintException;
    use Zef\Framework\Exception\RouteNotFoundException;
    use Zef\Framework\Validation\RouteConstraintValidator;

    /**
     * Public router facade (C4 / RM-09, non-line-based decomposition).
     *
     * Router owns registration state, the frozen lifecycle and dispatch
     * semantics. Cohesive internals are delegated to extracted components:
     *   - RoutePatternParser  : pattern/path parsing + canonical signatures
     *   - RouteOrdering       : deterministic route precedence
     *   - RadixIndex          : compiled radix tree build + traversal
     *   - RouteMatcher        : segment-vs-path matching + constraint test
     *
     * The public surface is unchanged: every public method signature,
     * visibility, return type and behavioral contract is preserved.
     */
    final class Router
    {
        /** @var list<array{method:string,pattern:string,handler:string,module:?string,priority:int,sequence:int,segments:list<array{dynamic:bool,name?:string,constraint?:string|null,value?:string}>,signature:string,staticCount:int,constrainedCount:int}> */
        private array $routes = [];
        /** @var array<string,bool> */
        private array $signatureIndex = [];
        private int $sequence = 0;
        private bool $frozen = false;
        private bool $sorted = true;
        private int $maxRoutesBudget = 10000;

        private readonly RoutePatternParser $parser;
        private readonly RouteOrdering $ordering;
        private readonly RadixIndex $radixIndex;
        private readonly RouteMatcher $matcher;

        /**
         * Immutable-after-freeze compiled radix index.
         *
         * Node layout:
         *   static   => map<string,int>
         *   dynamic  => map<string,int> where key is constraint name or '' for unconstrained
         *   routes   => sorted route indexes terminating at this node
         *
         * The tree is structural by design. Constraint validation remains in
         * matchRoute() so the framework preserves the existing 400 semantics
         * for structurally matching but constraint-invalid paths.
         *
         * @var list<array{
         *   static:array<string,int>,
         *   dynamic:array<string,int>,
         *   routes:list<int>
         * }>
         */
        private array $radix = [
            ['static' => [], 'dynamic' => [], 'routes' => []],
        ];

        public function __construct(
            private readonly RouteConstraintValidator $constraints = new RouteConstraintValidator(),
            ?\Zef\Framework\Policy\ArchitecturePolicy $policy = null,
        ) {
            if ($policy !== null) {
                $this->maxRoutesBudget = $policy->maxRouteRegistrations;
            }
            $this->parser = new RoutePatternParser();
            $this->ordering = new RouteOrdering();
            $this->radixIndex = new RadixIndex($this->parser);
            $this->matcher = new RouteMatcher($this->parser, $this->constraints);
        }

        public function setMaxRoutesBudget(int $max): void
        {
            if ($this->frozen) {
                throw new \LogicException('Router is frozen.');
            }
            if ($max < 1) {
                throw new \InvalidArgumentException('Route budget must be >= 1.');
            }
            if (count($this->routes) > $max) {
                throw new \InvalidArgumentException('Route budget cannot be lower than current route count.');
            }
            $this->maxRoutesBudget = $max;
        }

        public function getMaxRoutesBudget(): int
        {
            return $this->maxRoutesBudget;
        }

        public function add(
            string $method,
            string $pattern,
            string $handlerService,
            ?string $module = null,
            int $priority = 0,
        ): void {
            if ($this->frozen) {
                throw new \LogicException('Router is frozen.');
            }
            if (count($this->routes) >= $this->maxRoutesBudget) {
                throw new \Zef\Framework\Exception\InvalidConfigurationException(
                    "Router safety budget exceeded: maximum {$this->maxRoutesBudget} route registrations allowed.",
                );
            }

            $method = strtoupper(trim($method));
            if (
                $pattern === ''
                || $pattern[0] !== '/'
            ) {
                throw new \InvalidArgumentException("Route path '{$pattern}' must begin with '/'.");
            }
            if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $method) !== 1) {
                throw new \InvalidArgumentException("Invalid HTTP method '{$method}'.");
            }

            $segments = $this->parser->parse($pattern);
            $names = [];

            foreach ($segments as $segment) {
                if (!$segment['dynamic']) {
                    continue;
                }
                $name = $segment['name'] ?? null;
                if (!is_string($name)) {
                    throw new \LogicException('Dynamic route segment is missing its name.');
                }
                $constraint = $segment['constraint'] ?? null;

                if (isset($names[$name])) {
                    throw new \InvalidArgumentException("Duplicate route parameter '{$name}'.");
                }

                $names[$name] = true;
                if ($constraint !== null) {
                    $this->constraints->assertKnown($constraint);
                }
            }

            $signature = $this->parser->canonicalSignature($method, $segments);
            if (isset($this->signatureIndex[$signature])) {
                foreach ($this->routes as $existing) {
                    if ($existing['signature'] === $signature) {
                        throw new \InvalidArgumentException(
                            "Duplicate/unreachable route [{$method}] {$pattern}; it collides with {$existing['pattern']}.",
                        );
                    }
                }

                throw new \LogicException("Router signature index corruption detected for '{$signature}'.");
            }

            $staticCount = 0;
            $constrainedCount = 0;

            foreach ($segments as $segment) {
                if (!$segment['dynamic']) {
                    ++$staticCount;
                } elseif (($segment['constraint'] ?? null) !== null) {
                    ++$constrainedCount;
                }
            }

            $this->routes[] = [
                'method' => $method,
                'pattern' => $pattern,
                'handler' => $handlerService,
                'module' => $module,
                'priority' => $priority,
                'sequence' => $this->sequence++,
                'segments' => $segments,
                'signature' => $signature,
                'staticCount' => $staticCount,
                'constrainedCount' => $constrainedCount,
            ];

            $this->signatureIndex[$signature] = true;
            $this->sorted = false;
        }

        public function addConstraint(string $name, string $regex): void
        {
            if ($this->frozen) {
                throw new \LogicException('Router is frozen.');
            }
            $this->constraints->addCustom($name, $regex);
        }

        public function freeze(): void
        {
            if ($this->frozen) {
                return;
            }

            $this->sortRoutes();
            $this->compileRadix();
            $this->frozen = true;
        }

        /**
         * @return list<array{method:string,pattern:string,handler:string,module:?string,priority:int,sequence:int,segments:list<array{dynamic:bool,name?:string,constraint?:string|null,value?:string}>,signature:string,staticCount:int,constrainedCount:int}>
         */
        public function getRoutes(): array
        {
            $this->sortRoutes();
            return $this->routes;
        }

        /**
         * @return array{handler:string,module:?string,params:array<string,string>,pattern:string}
         */
        public function match(string $method, string $path): array
        {
            $this->sortRoutes();

            $method = strtoupper(trim($method));
            $effectiveMethods = $method === 'HEAD'
                ? ['HEAD', 'GET']
                : [$method];

            // Fast path: use the compiled radix tree plus constraint-aware edges.
            // Normal successful requests therefore do not scan structurally
            // compatible routes whose constraints already reject the segment.
            $constraintCandidates = $this->radixMatchingCandidates($path);
            foreach ($effectiveMethods as $effectiveMethod) {
                foreach ($constraintCandidates as $index) {
                    $route = $this->routes[$index];
                    if ($route['method'] !== $effectiveMethod) {
                        continue;
                    }

                    $params = $this->matcher->match($route['segments'], $path);
                    if ($params === false || $params instanceof RouteConstraintException) {
                        continue;
                    }

                    return [
                        'handler' => $route['handler'],
                        'module' => $route['module'],
                        'params' => $params,
                        'pattern' => $route['pattern'],
                    ];
                }
            }

            // Slow/failure path: retain structural candidates so constraint
            // failures remain 400-class and method mismatches remain 405.
            $constraintFailure = null;
            $allowed = [];
            $candidates = $this->radixCandidates($path);

            foreach ($candidates as $index) {
                $route = $this->routes[$index];
                $allowed[$route['method']] = true;
                if ($route['method'] === 'GET') {
                    $allowed['HEAD'] = true;
                }

                foreach ($effectiveMethods as $effectiveMethod) {
                    if ($route['method'] !== $effectiveMethod) {
                        continue;
                    }
                    $params = $this->matcher->match($route['segments'], $path);
                    if ($params instanceof RouteConstraintException) {
                        $constraintFailure ??= $params;
                    }
                }
            }

            if ($constraintFailure instanceof RouteConstraintException) {
                throw $constraintFailure;
            }

            if ($allowed !== []) {
                throw new \Zef\Framework\Exception\MethodNotAllowedException(
                    $method,
                    $path,
                    array_keys($allowed),
                );
            }

            throw new RouteNotFoundException($method, $path);
        }

        private function sortRoutes(): void
        {
            if ($this->sorted) {
                return;
            }

            $this->ordering->sort($this->routes);
            $this->sorted = true;
        }

        private function compileRadix(): void
        {
            $this->radix = $this->radixIndex->compile($this->routes);
        }

        /**
         * Constraint-aware radix traversal used by the successful request path.
         *
         * @return list<int>
         */
        private function radixMatchingCandidates(string $path): array
        {
            if (!$this->frozen) {
                $this->compileRadix();
            }

            return $this->radixIndex->matchingCandidates($this->radix, $path, $this->constraints);
        }

        /**
         * Structural radix traversal used by the slow/failure path.
         *
         * @return list<int>
         */
        private function radixCandidates(string $path): array
        {
            if (!$this->frozen) {
                $this->compileRadix();
            }

            return $this->radixIndex->structuralCandidates($this->radix, $path);
        }
    }
}
