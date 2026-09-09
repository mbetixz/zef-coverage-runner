<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ContainerResolver;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Container\ServiceRegistry;
use Zef\Framework\Container\ServiceRegistrar;
use Zef\Framework\Container\ServiceRegistryView;
use Zef\Framework\Exception\InvalidFactoryException;
use Zef\Framework\Exception\ServiceNotFoundException;
use Zef\Framework\Policy\ArchitecturePolicy;

final class ContainerDetailedTest extends TestCase
{
    private function assertThrows(string $class, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if ($e instanceof $class) {
                $this->addToAssertionCount(1);
                return;
            }
            self::fail(sprintf('Expected %s, got %s: %s', $class, $e::class, $e->getMessage()));
        }
        self::fail(sprintf('Expected %s to be thrown, nothing was thrown.', $class));
    }

    public function testSingletonResolutionAndCache(): void
    {
        $c = new Container();
        $count = 0;
        $c->register('svc', static function () use (&$count): object {
            ++$count;
            return new stdClass();
        });
        $c->validateAndFreeze();

        $a = $c->get('svc');
        $b = $c->get('svc');
        self::assertSame($a, $b, 'singleton must be cached');
        self::assertSame(1, $count, 'factory must run exactly once');
        self::assertTrue($c->has('svc'));
        self::assertFalse($c->has('missing'));
        self::assertTrue($c->isFrozen());
        self::assertFalse($c->isDebug());
    }

    public function testDebugFlagAndPolicy(): void
    {
        $c = new Container(debug: true);
        self::assertTrue($c->isDebug());
        $c->configurePolicies(3);
        $c->register('a', static fn () => new stdClass());
        $c->validateAndFreeze();
        self::assertTrue($c->isFrozen());
    }

    public function testArchitecturePolicyValidation(): void
    {
        self::assertThrows(\InvalidArgumentException::class, static fn () => new ArchitecturePolicy(maxCrossModuleRefs: -1));
        self::assertThrows(\InvalidArgumentException::class, static fn () => new ArchitecturePolicy(maxServiceRegistrations: 0));
        self::assertThrows(\InvalidArgumentException::class, static fn () => new ArchitecturePolicy(maxResolutionDepth: 0));
        $p = new ArchitecturePolicy(maxServiceRegistrations: 1);
        self::assertSame(1, $p->maxServiceRegistrations);
    }

    public function testServiceRegistrationBudgetExceededThrows(): void
    {
        $c = new Container(false, new ArchitecturePolicy(maxServiceRegistrations: 1));
        $c->register('one', static fn () => new stdClass());
        self::assertThrows(
            \Zef\Framework\Exception\InvalidConfigurationException::class,
            static fn () => $c->register('two', static fn () => new stdClass()),
        );
    }

    public function testConstructorSecondArgumentIsPolicy(): void
    {
        $policy = new ArchitecturePolicy(maxResolutionDepth: 8);
        $c = new Container(false, $policy);
        $c->register('svc', static fn () => new stdClass());
        $c->validateAndFreeze();
        self::assertInstanceOf(stdClass::class, $c->get('svc'));
    }

    public function testRegisterDuplicateThrows(): void
    {
        $c = new Container();
        $c->register('svc', static fn () => new stdClass());
        self::assertThrows(InvalidFactoryException::class, static fn () => $c->register('svc', static fn () => new stdClass()));
    }

    public function testAliasResolution(): void
    {
        $c = new Container();
        $c->register('real', static fn () => new stdClass());
        $c->alias('fake', 'real');
        $c->validateAndFreeze();
        self::assertSame($c->get('real'), $c->get('fake'));
        self::assertTrue($c->has('fake'));
        $map = $c->getAliasMap();
        self::assertSame('real', $map['fake']);
    }

    public function testAliasToMissingThrowsOnCompile(): void
    {
        $c = new Container();
        $c->alias('orphan', 'ghost');
        self::assertThrows(ServiceNotFoundException::class, static fn () => $c->validateAndFreeze());
    }

    public function testDependencyInjectionThroughCtx(): void
    {
        $c = new Container();
        $c->register('dep', static fn () => 'payload');
        $c->register(
            'main',
            static function ($ctx, $dep): array { return ['ctx' => $ctx, 'dep' => $dep]; },
            ['dep'],
        );
        $c->validateAndFreeze();
        $resolved = $c->get('main');
        /** @var array{ctx: \Psr\Container\ContainerInterface, dep: string} $resolved */
        self::assertSame('payload', $resolved['dep']);
        self::assertInstanceOf(\Psr\Container\ContainerInterface::class, $resolved['ctx']);
    }

    public function testTransientIsNotShared(): void
    {
        $c = new Container();
        $c->register('t', static fn () => new stdClass(), [], null, ServiceLifetime::TRANSIENT);
        $c->validateAndFreeze();
        self::assertNotSame($c->get('t'), $c->get('t'));
    }

    public function testRequestScopeLifecycle(): void
    {
        $c = new Container();
        $c->register('req', static fn () => new stdClass(), [], null, ServiceLifetime::REQUEST);
        $c->validateAndFreeze();

        $scope = $c->createRequestScope();
        $a = $scope->get('req');
        $b = $scope->get('req');
        self::assertSame($a, $b, 'request service cached inside scope');
        self::assertTrue($scope->has('req'));
        self::assertTrue($scope->hasInstance('req'));
        self::assertSame($a, $scope->getInstance('req'));

        $scope2 = $c->createRequestScope();
        self::assertNotSame($a, $scope2->get('req'), 'scopes must be isolated');

        $scope->close();
        self::assertTrue($scope->isClosed());
        self::assertThrows(\LogicException::class, static fn () => $scope->get('req'));
        self::assertThrows(\LogicException::class, static fn () => $scope->getInstance('req'));
        self::assertThrows(\LogicException::class, static fn () => $scope->setInstance('x', new stdClass()));
        self::assertFalse($scope->has('req'));
        self::assertFalse($scope->hasInstance('req'));
        $scope2->close();
    }

    public function testRequestScopedResolvedFromRootOutsideScopeThrows(): void
    {
        $c = new Container();
        $c->register('req', static fn () => new stdClass(), [], null, ServiceLifetime::REQUEST);
        $c->validateAndFreeze();
        // resolveRoot wraps LogicException into ServiceResolutionException.
        self::assertThrows(
            \Zef\Framework\Exception\ServiceResolutionException::class,
            static fn () => $c->get('req'),
        );
    }

    public function testCircularDependencyThrows(): void
    {
        $c = new Container();
        $c->register('x', static fn () => new stdClass(), ['y']);
        $c->register('y', static fn () => new stdClass(), ['x']);
        self::assertThrows(
            \Zef\Framework\Exception\ServiceCircularDependencyException::class,
            static fn () => $c->validateAndFreeze(),
        );
    }

    public function testFactoryReturningNullThrows(): void
    {
        $c = new Container();
        $c->register('nullsvc', static fn () => null);
        $c->validateAndFreeze();
        self::assertThrows(
            \Zef\Framework\Exception\ServiceResolutionException::class,
            static fn () => $c->get('nullsvc'),
        );
    }

    public function testDeepChainAndResolutionDepthGuard(): void
    {
        $policy = new ArchitecturePolicy(maxResolutionDepth: 3);
        $c = new Container(false, $policy);
        $c->register('l0', static fn () => new stdClass(), ['l1']);
        $c->register('l1', static fn () => new stdClass(), ['l2']);
        $c->register('l2', static fn () => new stdClass(), ['l3']);
        $c->register('l3', static fn () => new stdClass(), ['l4']);
        $c->register('l4', static fn () => new stdClass());
        $c->validateAndFreeze();
        self::assertThrows(\Throwable::class, static fn () => $c->get('l0'));
    }

    public function testWarmSingletons(): void
    {
        $c = new Container();
        $count = 0;
        $c->register('warm', static function () use (&$count): object {
            ++$count;
            return new stdClass();
        });
        $c->register('lazy', static fn () => new stdClass(), [], null, ServiceLifetime::SINGLETON);
        $c->validateAndFreeze();
        $c->warmSingletons();
        self::assertSame(1, $count, 'eager singleton warmed');
    }

    public function testWarmBeforeFreezeThrows(): void
    {
        $c = new Container();
        self::assertThrows(\LogicException::class, static fn () => $c->warmSingletons());
    }

    public function testGetMissingServiceThrows(): void
    {
        $c = new Container();
        $c->validateAndFreeze();
        self::assertThrows(ServiceNotFoundException::class, static fn () => $c->get('nope'));
    }

    public function testRegisterDefinitionValidation(): void
    {
        $c = new Container();
        $c->registerDefinition(new ServiceDefinition('via-def', static fn () => new stdClass()));
        $c->register('via-fn', static fn () => new stdClass());
        self::assertThrows(
            InvalidFactoryException::class,
            static fn () => $c->registerDefinition(new ServiceDefinition('via-fn', static fn () => new stdClass())),
        );
        $c->validateAndFreeze();
        self::assertInstanceOf(stdClass::class, $c->get('via-def'));
    }

    public function testGetRegisteredIdsAndRegistryView(): void
    {
        $c = new Container();
        $c->register('alpha', static fn () => new stdClass());
        $c->register('beta', static fn () => new stdClass());
        $c->alias('b2', 'beta');
        $ids = $c->getRegisteredIds();
        self::assertContains('alpha', $ids);
        self::assertContains('beta', $ids);
        self::assertContains('b2', $ids);

        $view = $c->getRegistry();
        self::assertInstanceOf(ServiceRegistryView::class, $view);
        self::assertArrayHasKey('alpha', $view->definitions());
        self::assertArrayHasKey('alpha', $view->factories());
        self::assertArrayHasKey('b2', $view->aliases());
        self::assertArrayHasKey('alpha', $view->depsOf());
        self::assertArrayHasKey('alpha', $view->moduleOf());
        self::assertArrayHasKey('alpha', $view->lifetimeOf());
    }

    public function testResetAndClearSingletons(): void
    {
        $c = new Container();
        $c->register('svc', static fn () => new stdClass());
        $c->validateAndFreeze();
        $first = $c->get('svc');
        $c->reset();
        self::assertSame($first, $c->get('svc'), 'reset without clear keeps singleton');
        $c->reset(clearSingletons: true);
        self::assertNotSame($first, $c->get('svc'), 'clearSingletons resets instance');
    }

    public function testRegistryUnitApi(): void
    {
        $reg = new ServiceRegistry();
        self::assertFalse($reg->hasFactory('x'));
        self::assertFalse($reg->hasAlias('x'));
        $reg->addFactory('x', static fn () => new stdClass(), [], 'mod', ServiceLifetime::SINGLETON);
        $reg->addAlias('x2', 'x', 'mod');
        self::assertTrue($reg->hasFactory('x'));
        self::assertTrue($reg->hasAlias('x2'));
        self::assertSame('x', $reg->aliases()['x2']);
        self::assertSame('mod', $reg->moduleOf()['x']);
        self::assertSame('singleton', $reg->lifetimeOf()['x']);
        self::assertFalse($reg->hasInstance('x'));
        $obj = new stdClass();
        $reg->setInstance('x', $obj);
        self::assertTrue($reg->hasInstance('x'));
        self::assertSame($obj, $reg->instance('x'));
        $reg->clearInstances();
        self::assertFalse($reg->hasInstance('x'));
        self::assertCount(0, $reg->depsOf()['x']);
    }

    public function testRegistrarUnitApi(): void
    {
        $reg = new ServiceRegistry();
        $registrar = new ServiceRegistrar($reg);
        $registrar->register('svc', static fn () => new stdClass(), ['dep'], 'm', ServiceLifetime::TRANSIENT);
        self::assertThrows(InvalidFactoryException::class, static fn () => $registrar->register('svc', static fn () => new stdClass()));
        self::assertThrows(InvalidFactoryException::class, static fn () => $registrar->register('', static fn () => new stdClass()));
        self::assertThrows(InvalidFactoryException::class, static fn () => $registrar->register('bad', static fn () => new stdClass(), [123]));
        self::assertThrows(\InvalidArgumentException::class, static fn () => $registrar->register('badlife', static fn () => new stdClass(), [], null, 'nope'));
        $registrar->alias('a2', 'svc');
        self::assertThrows(InvalidFactoryException::class, static fn () => $registrar->alias('a2', 'other'));
        self::assertThrows(InvalidFactoryException::class, static fn () => $registrar->alias('', 'svc'));
        self::assertThrows(InvalidFactoryException::class, static fn () => $registrar->alias('newalias', ''));
    }

    public function testResolverScopePublicApi(): void
    {
        $c = new Container();
        $c->register('scoped', static fn () => new stdClass(), [], null, ServiceLifetime::REQUEST);
        $c->validateAndFreeze();
        $scope = $c->createRequestScope();
        $obj = new stdClass();
        $scope->setInstance('manual', $obj);
        self::assertTrue($scope->hasInstance('manual'));
        self::assertSame($obj, $scope->getInstance('manual'));
        self::assertTrue($scope->has('scoped'));
        $scope->close();
    }

    public function testConcurrentInitializationGuard(): void
    {
        // Re-entrant resolution of the same singleton while it is being built.
        $c2 = new Container();
        $c2->register('outer', static function (\Zef\Framework\Container\ResolutionContext $ctx): object {
            $ctx->get('outer');
            return new stdClass();
        });
        $c2->validateAndFreeze();
        self::assertThrows(\Throwable::class, static fn () => $c2->get('outer'));
    }

    public function testHasBeforeAndAfterFreeze(): void
    {
        $c = new Container();
        $c->register('svc', static fn () => new stdClass());
        self::assertTrue($c->has('svc'));
        $c->validateAndFreeze();
        self::assertTrue($c->has('svc'));
        self::assertFalse($c->has('ghost'));
    }

    public function testUnboundResolverThrows(): void
    {
        $reg = new ServiceRegistry();
        $resolver = new ContainerResolver($reg, new \Zef\Framework\Validation\DependencyGraphValidator());
        $depth = $resolver->maxResolutionDepth();
        self::assertGreaterThanOrEqual(1, $depth);
        self::assertThrows(\LogicException::class, static fn () => $resolver->resolveRoot('x'));
    }
}
