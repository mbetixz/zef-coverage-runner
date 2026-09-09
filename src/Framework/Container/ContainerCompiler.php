<?php

declare(strict_types=1);

namespace Zef\Framework\Container;

use Zef\Framework\Exception\ServiceNotFoundException;
use Zef\Framework\Validation\DependencyGraphValidator;

/**
 * Compiles the mutable registration registry into an immutable runtime plan.
 *
 * Compilation happens only at the lifecycle boundary (freeze). Request-time
 * resolution therefore avoids repeated alias traversal, definition map
 * materialization and dependency-array reconstruction.
 *
 * @internal
 */
final class ContainerCompiler
{
    public function __construct(private readonly DependencyGraphValidator $graphValidator)
    {
    }

    /**
     * @throws ServiceNotFoundException when an alias resolves to no definition.
     */
    public function compile(
        ServiceRegistry $registry,
        int $maxCrossModuleRefs = 0,
    ): CompiledContainerPlan {
        /** @var array<string,ServiceDefinition> $definitions */
        $definitions = $registry->definitions();
        /** @var array<string,string> $aliases */
        $aliases = $registry->aliases();
        /** @var array<string,list<string>> $deps */
        $deps = $registry->depsOf();
        /** @var array<string,?string> $moduleOf */
        $moduleOf = $registry->moduleOf();
        /** @var array<string,string> $lifetimes */
        $lifetimes = $registry->lifetimeOf();

        $this->graphValidator->validate(
            $registry->factories(),
            $aliases,
            $deps,
            $moduleOf,
            $lifetimes,
            $maxCrossModuleRefs,
        );

        /** @var array<string,string> $canonicalIds */
        $canonicalIds = [];
        foreach (array_keys($definitions) as $id) {
            $canonicalIds[$id] = $id;
        }
        foreach (array_keys($aliases) as $alias) {
            $canonical = $this->graphValidator->resolveAlias($alias, $aliases);
            if (!isset($definitions[$canonical])) {
                $module = $moduleOf[$alias] ?? null;
                throw new ServiceNotFoundException($alias, is_string($module) ? $module : null);
            }
            $canonicalIds[$alias] = $canonical;
        }

        /** @var array<string,list<string>> $compiledDependencies */
        $compiledDependencies = [];
        foreach ($definitions as $id => $definition) {
            $compiledDependencies[$id] = [];
            foreach ($definition->dependencies as $dependency) {
                if (!is_string($dependency)) {
                    throw new \LogicException('Compiled service dependency must be a string.');
                }
                $compiledDependencies[$id][] = $canonicalIds[$dependency] ?? $dependency;
            }
        }

        /** @var array<string,bool> $shared */
        $shared = [];
        /** @var array<string,bool> $lazy */
        $lazy = [];
        foreach ($definitions as $id => $definition) {
            $shared[$id] = $definition->shared;
            $lazy[$id] = $definition->lazy;
        }

        return new CompiledContainerPlan(
            definitions: $definitions,
            aliases: $aliases,
            canonicalIds: $canonicalIds,
            dependencies: $compiledDependencies,
            lifetimes: $lifetimes,
            shared: $shared,
            lazy: $lazy,
        );
    }
}
