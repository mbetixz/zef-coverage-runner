<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;
    use Zef\Framework\Container\Container;
    use Zef\Framework\Container\ServiceDefinition;
    use Zef\Framework\Container\ServiceLifetime;
    use Zef\Framework\Exception\InvalidFactoryException;
    use Zef\Framework\Exception\ServiceNotFoundException;
    use Zef\Framework\Exception\ServiceResolutionException;
    use Zef\Framework\Policy\ArchitecturePolicy;

    /**
     * Batch 18 coverage: guard-rail statements in the Container sub-tree that
     * existing suites do not execute (all reached through public API only):
     *
     *  - Container::configurePolicies()/register()/registerDefinition()/alias()
     *    throw \LogicException once the container is frozen
     *    (Container.php L44/L58/L71/L89) and registerDefinition() enforces the
     *    service-registration budget (L74-76).
     *  - ServiceDefinition rejects a non-callable factory (L35) and non-string
     *    tags (L44-45); fromArray() rejects non-array deps (L64) and a
     *    non-string lifetime (L68) as InvalidFactoryException.
     *  - ContainerResolver throws ServiceNotFoundException for an unknown id on
     *    the uncompiled path (L68), rethrows a ServiceResolutionException
     *    thrown by a factory (L110) and hasInContext() returns false when an
     *    alias cycle makes alias resolution throw (L160-161).
     *
     * The remaining uncovered Container-subtree lines (ContainerCompiler
     * L60-61/L72, ContainerResolver L80/L103, Router L130/L154/L238,
     * Telemetry L95/L117/L120/L137-148, CorrelationContext L40) are defensive
     * guards unreachable through any public API without corrupting internal
     * state, so they are intentionally left uncovered (documented in the
     * batch report).
     *
     * Determinism: pure in-memory object graphs; no env, network, clock or
     * randomness. Supply chain: no new dependencies; tests/Regression/
     * untouched.
     */
    final class Batch18ContainerCoverageTest extends TestCase
    {
        public function testConfigurePoliciesAfterFreezeThrows(): void
        {
            $container = new Container();
            $container->register('svc.a', static fn (): object => new \stdClass());
            $container->validateAndFreeze();
            try {
                $container->configurePolicies(5);
                self::fail('configurePolicies after freeze must throw.');
            } catch (\LogicException $e) {
                self::assertStringContainsString('frozen', $e->getMessage());
            }
        }

        public function testRegisterAfterFreezeThrows(): void
        {
            $container = new Container();
            $container->register('svc.a', static fn (): object => new \stdClass());
            $container->validateAndFreeze();
            try {
                $container->register('late.svc', static fn (): object => new \stdClass());
                self::fail('register after freeze must throw.');
            } catch (\LogicException $e) {
                self::assertStringContainsString('frozen', $e->getMessage());
            }
        }

        public function testRegisterDefinitionAfterFreezeThrows(): void
        {
            $container = new Container();
            $container->register('svc.a', static fn (): object => new \stdClass());
            $container->validateAndFreeze();
            try {
                $container->registerDefinition(new ServiceDefinition('late.def', static fn (): object => new \stdClass()));
                self::fail('registerDefinition after freeze must throw.');
            } catch (\LogicException $e) {
                self::assertStringContainsString('frozen', $e->getMessage());
            }
        }

        public function testAliasAfterFreezeThrows(): void
        {
            $container = new Container();
            $container->register('svc.a', static fn (): object => new \stdClass());
            $container->validateAndFreeze();
            try {
                $container->alias('late.alias', 'svc.a');
                self::fail('alias after freeze must throw.');
            } catch (\LogicException $e) {
                self::assertStringContainsString('frozen', $e->getMessage());
            }
        }

        public function testRegisterDefinitionBeyondBudgetThrows(): void
        {
            $container = new Container(false, new ArchitecturePolicy(maxServiceRegistrations: 1));
            $container->register('first', static fn (): object => new \stdClass());
            try {
                $container->registerDefinition(new ServiceDefinition('second', static fn (): object => new \stdClass()));
                self::fail('registerDefinition beyond budget must throw.');
            } catch (\Zef\Framework\Exception\InvalidConfigurationException $e) {
                self::assertStringContainsString('budget', $e->getMessage());
            }
        }

        public function testServiceDefinitionRejectsNonCallableFactory(): void
        {
            try {
                new ServiceDefinition('bad.factory', 'not-a-callable');
                self::fail('non-callable factory must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('callable', $e->getMessage());
            }
        }

        public function testServiceDefinitionRejectsNonStringTags(): void
        {
            try {
                new ServiceDefinition('bad.tags', static fn (): object => new \stdClass(), [], null, ServiceLifetime::SINGLETON, true, false, [42]);
                self::fail('non-string tags must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('tags', $e->getMessage());
            }
        }

        public function testServiceDefinitionFromArrayRejectsNonArrayDeps(): void
        {
            try {
                ServiceDefinition::fromArray('bad.deps', ['factory' => static fn (): object => new \stdClass(), 'deps' => 'oops']);
                self::fail('non-array deps must be rejected.');
            } catch (InvalidFactoryException $e) {
                self::assertStringContainsString('dependencies must be an array', $e->getMessage());
            }
        }

        public function testServiceDefinitionFromArrayRejectsNonStringLifetime(): void
        {
            try {
                ServiceDefinition::fromArray('bad.lifetime', ['factory' => static fn (): object => new \stdClass(), 'lifetime' => 42]);
                self::fail('non-string lifetime must be rejected.');
            } catch (InvalidFactoryException $e) {
                self::assertStringContainsString('lifetime must be a string', $e->getMessage());
            }
        }

        public function testResolverUnknownIdBeforeFreezeThrows(): void
        {
            // Uncompiled path (ContainerResolver.php L68): the container has
            // not been frozen, so no plan exists and resolution falls back to
            // the live registry, which has no definition for the id.
            $container = new Container();
            try {
                $container->get('missing.pre.freeze');
                self::fail('unknown id before freeze must throw.');
            } catch (ServiceNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        public function testResolverRethrowsServiceResolutionExceptionFromFactory(): void
        {
            // A factory that throws ServiceResolutionException must propagate
            // unchanged through the initialization guard wrapper
            // (ContainerResolver.php L108-110).
            $container = new Container();
            $container->register('boom', static function (): never {
                throw new ServiceResolutionException('boom', 'factory exploded');
            });
            $container->validateAndFreeze();
            try {
                $container->get('boom');
                self::fail('factory exception must propagate.');
            } catch (ServiceResolutionException $e) {
                self::assertStringContainsString('factory exploded', $e->getMessage());
            }
        }

        public function testHasInContextWithAliasCycleReturnsFalse(): void
        {
            // A circular alias chain makes alias resolution throw inside
            // hasInContext(); the catch-all guard must convert that into
            // false (ContainerResolver.php L160-161).
            $container = new Container();
            $container->alias('cycle.a', 'cycle.b');
            $container->alias('cycle.b', 'cycle.a');
            self::assertFalse($container->has('cycle.a'));
            self::assertFalse($container->has('cycle.b'));
            self::assertFalse($container->has('unrelated.id'));
        }
    }
}
