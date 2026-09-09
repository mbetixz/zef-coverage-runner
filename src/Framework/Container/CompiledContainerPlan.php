<?php

declare(strict_types=1);

namespace Zef\Framework\Container;

/**
 * Immutable, pre-resolved dependency graph used by the runtime resolver.
 *
 * @internal
 */
final readonly class CompiledContainerPlan
{
    /**
     * @param array<string,ServiceDefinition> $definitions
     * @param array<string,string> $aliases
     * @param array<string,string> $canonicalIds
     * @param array<string,list<string>> $dependencies
     * @param array<string,string> $lifetimes
     * @param array<string,bool> $shared
     * @param array<string,bool> $lazy
     */
    public function __construct(public array $definitions, public array $aliases, public array $canonicalIds, public array $dependencies, public array $lifetimes, public array $shared, public array $lazy)
    {
    }

    /** @return string|null Canonical service ID, or null when the ID is not compiled. */
    public function canonical(string $id): ?string
    {
        return $this->canonicalIds[$id] ?? null;
    }

    /** @return list<string> Canonical dependency IDs for the compiled service. */
    public function dependenciesOf(string $id): array
    {
        return $this->dependencies[$id] ?? [];
    }
}
