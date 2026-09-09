<?php

declare(strict_types=1);

namespace Zef\Framework\Router {

    /**
     * Deterministic route ordering comparator extracted from Router (C4 / RM-09,
     * non-line-based). Orders by priority desc, staticCount desc,
     * constrainedCount desc, then registration sequence asc. Stateless.
     */
    final class RouteOrdering
    {
        /**
         * Sort routes in place using the canonical precedence comparator.
         *
         * @param list<array{method:string,pattern:string,handler:string,module:?string,priority:int,sequence:int,segments:list<array{dynamic:bool,name?:string,constraint?:string|null,value?:string}>,signature:string,staticCount:int,constrainedCount:int}> $routes
         */
        public function sort(array &$routes): void
        {
            usort(
                $routes,
                static function (array $a, array $b): int {
                    foreach (['priority', 'staticCount', 'constrainedCount'] as $field) {
                        $cmp = $b[$field] <=> $a[$field];
                        if ($cmp !== 0) {
                            return $cmp;
                        }
                    }

                    return $a['sequence'] <=> $b['sequence'];
                },
            );
        }
    }
}
