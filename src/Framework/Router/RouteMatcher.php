<?php

declare(strict_types=1);

namespace Zef\Framework\Router {

    use Zef\Framework\Exception\RouteConstraintException;
    use Zef\Framework\Validation\RouteConstraintValidator;

    /**
     * Segment-vs-path matcher extracted from Router (C4 / RM-09,
     * non-line-based). Validates dynamic segments against their registered
     * constraint and returns captured parameters, false on shape mismatch, or
     * a RouteConstraintException when a structurally matching segment violates
     * its constraint.
     */
    final readonly class RouteMatcher
    {
        public function __construct(
            private readonly RoutePatternParser $parser,
            private readonly RouteConstraintValidator $constraints,
        ) {
        }

        /**
         * @param list<array{dynamic:bool,name?:string,constraint?:string|null,value?:string}> $segments
         *
         * @return array<string,string>|false|RouteConstraintException
         */
        public function match(array $segments, string $path): array|false|RouteConstraintException
        {
            $pathParts = $this->parser->splitPath($path);

            if (count($segments) !== count($pathParts)) {
                return false;
            }

            $params = [];

            foreach ($segments as $index => $segment) {
                $value = $pathParts[$index] ?? '';

                if ($segment['dynamic']) {
                    $name = $segment['name'] ?? null;
                    if (!is_string($name)) {
                        throw new \LogicException('Dynamic route segment is missing its name.');
                    }
                    $constraint = $segment['constraint'] ?? null;
                    if (
                        $constraint !== null
                        && !$this->constraints->test(
                            $name,
                            $constraint,
                            $value,
                        )
                    ) {
                        return new RouteConstraintException(
                            $name,
                            $constraint,
                            $value,
                        );
                    }

                    $params[$name] = $value;
                    continue;
                }

                $staticValue = $segment['value'] ?? '';
                if ($staticValue !== $value) {
                    return false;
                }
            }

            return $params;
        }
    }
}
