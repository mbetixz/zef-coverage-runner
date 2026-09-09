<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Exception\RouteNotFoundException;
use Zef\Framework\Router\Router;
use Zef\Framework\Validation\RouteConstraintValidator;

final class RouterDetailedTest extends TestCase
{
    private Router $router;

    #[Override]
    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testDefaultBudgetIsLarge(): void
    {
        $this->assertSame(10000, $this->router->getMaxRoutesBudget());
    }

    public function testSetMaxRoutesBudget(): void
    {
        $this->router->setMaxRoutesBudget(50);
        $this->assertSame(50, $this->router->getMaxRoutesBudget());
    }

    public function testSetMaxRoutesBudgetRejectsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->router->setMaxRoutesBudget(0);
    }

    public function testSetMaxRoutesBudgetRejectsBelowCurrentCount(): void
    {
        $this->router->add('GET', '/one', 'h1');
        $this->router->add('GET', '/two', 'h2');

        $this->expectException(\InvalidArgumentException::class);
        $this->router->setMaxRoutesBudget(1);
    }

    public function testBudgetExceededThrows(): void
    {
        $this->router->setMaxRoutesBudget(1);
        $this->router->add('GET', '/only', 'h1');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('budget exceeded');
        $this->router->add('GET', '/second', 'h2');
    }

    public function testAddRejectsEmptyPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->router->add('GET', '', 'h1');
    }

    public function testAddRejectsPatternWithoutLeadingSlash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->router->add('GET', 'no-slash', 'h1');
    }

    public function testAddRejectsInvalidHttpMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->router->add('GE T', '/x', 'h1');
    }

    public function testAddNormalizesMethodToUppercase(): void
    {
        $this->router->add('get', '/case', 'h1');
        $routes = $this->router->getRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame('GET', $routes[0]['method']);
    }

    public function testAddRejectsDuplicateParameterNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate route parameter');
        $this->router->add('GET', '/x/{id}/{id}', 'h1');
    }

    public function testAddRejectsUnknownConstraint(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->router->add('GET', '/x/{id:unknown_type}', 'h1');
    }

    public function testAddRejectsDuplicateSignatureCollision(): void
    {
        $this->router->add('GET', '/a/{id:int}', 'h1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate/unreachable route');
        $this->router->add('GET', '/a/{other:int}', 'h2');
    }

    public function testAddAllowsDistinctMethodsOnSamePattern(): void
    {
        $this->router->add('GET', '/r', 'h-get');
        $this->router->add('POST', '/r', 'h-post');

        $this->assertCount(2, $this->router->getRoutes());
    }

    public function testGetRoutesSortsByPriorityDescending(): void
    {
        $this->router->add('GET', '/low', 'h-low', priority: 1);
        $this->router->add('GET', '/high', 'h-high', priority: 100);
        $this->router->add('GET', '/mid', 'h-mid', priority: 10);

        $routes = $this->router->getRoutes();
        $this->assertSame(['/high', '/mid', '/low'], array_column($routes, 'pattern'));
    }

    public function testGetRoutesStableBySequenceForEqualPriority(): void
    {
        $this->router->add('GET', '/first', 'h1');
        $this->router->add('GET', '/second', 'h2');

        $routes = $this->router->getRoutes();
        $this->assertSame(['/first', '/second'], array_column($routes, 'pattern'));
    }

    public function testGetRoutesPrefersStaticOverDynamic(): void
    {
        $this->router->add('GET', '/users/{id}', 'h-dynamic');
        $this->router->add('GET', '/users/me', 'h-static');

        $routes = $this->router->getRoutes();
        $this->assertSame(['/users/me', '/users/{id}'], array_column($routes, 'pattern'));
    }

    public function testMatchFastPathCapturesParams(): void
    {
        $this->router->add('GET', '/users/{id:int}/posts/{slug}', 'post.handler', 'blog');

        $result = $this->router->match('GET', '/users/42/posts/hello-world');

        $this->assertSame('post.handler', $result['handler']);
        $this->assertSame('blog', $result['module']);
        $this->assertSame(['id' => '42', 'slug' => 'hello-world'], $result['params']);
        $this->assertSame('/users/{id:int}/posts/{slug}', $result['pattern']);
    }

    public function testMatchHeadFallsBackToGet(): void
    {
        $this->router->add('GET', '/headable', 'h1');

        $result = $this->router->match('HEAD', '/headable');
        $this->assertSame('h1', $result['handler']);
    }

    public function testMatchNormalizesMethodAndPathInput(): void
    {
        $this->router->add('GET', '/norm', 'h1');

        $result = $this->router->match(' get ', '/norm');
        $this->assertSame('h1', $result['handler']);
    }

    public function testMatchConstraintFailureThrowsRouteConstraintException(): void
    {
        $this->router->add('GET', '/c/{id:int}', 'h1');

        $this->expectException(RouteConstraintException::class);
        $this->router->match('GET', '/c/not-an-int');
    }

    public function testMatchMethodMismatchThrowsMethodNotAllowed(): void
    {
        $this->router->add('GET', '/only-get', 'h1');

        try {
            $this->router->match('POST', '/only-get');
            $this->fail('Expected MethodNotAllowedException.');
        } catch (MethodNotAllowedException $e) {
            $this->assertSame('POST', $e->method);
            $this->assertSame('/only-get', $e->path);
            $this->assertContains('GET', $e->allowedMethods);
            $this->assertContains('HEAD', $e->allowedMethods);
        }
    }

    public function testMatchNoRouteThrowsNotFound(): void
    {
        $this->router->add('GET', '/exists', 'h1');

        try {
            $this->router->match('GET', '/missing');
            $this->fail('Expected RouteNotFoundException.');
        } catch (RouteNotFoundException $e) {
            $this->assertSame('GET', $e->method);
            $this->assertSame('/missing', $e->path);
        }
    }

    public function testMatchBeforeFreezeAutoCompiles(): void
    {
        $this->router->add('GET', '/auto/{id}', 'h1');

        $result = $this->router->match('GET', '/auto/7');
        $this->assertSame(['id' => '7'], $result['params']);
    }

    public function testCustomConstraintRegisteredThroughRouter(): void
    {
        $this->router->addConstraint('year', '/^\d{4}$/');
        $this->router->add('GET', '/events/{y:year}', 'event.handler');

        $ok = $this->router->match('GET', '/events/2026');
        $this->assertSame(['y' => '2026'], $ok['params']);

        $this->expectException(RouteConstraintException::class);
        $this->router->match('GET', '/events/26');
    }

    public function testAddConstraintRejectsInvalidName(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->router->addConstraint('bad-name!', '/x/');
    }

    public function testAddConstraintRejectsReDoSRegex(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->router->addConstraint('evil', '/(a+)+$/');
    }

    public function testFreezeIsIdempotentAndLocksRegistration(): void
    {
        $this->router->add('GET', '/frozen', 'h1');
        $this->router->freeze();
        $this->router->freeze(); // no-op

        $result = $this->router->match('GET', '/frozen');
        $this->assertSame('h1', $result['handler']);

        $this->expectException(\LogicException::class);
        $this->router->add('GET', '/late', 'h2');
    }

    public function testFreezeLocksAddConstraintAndBudget(): void
    {
        $this->router->freeze();

        try {
            $this->router->addConstraint('x', '/x/');
            $this->fail('Expected LogicException.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('frozen', $e->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->router->setMaxRoutesBudget(5);
    }

    public function testMatchSelectsMostSpecificStaticRouteOverDynamic(): void
    {
        $this->router->add('GET', '/users/{id}', 'dynamic-handler');
        $this->router->add('GET', '/users/me', 'static-handler');

        $result = $this->router->match('GET', '/users/me');
        $this->assertSame('static-handler', $result['handler']);
    }

    public function testMatchWithCustomValidatorInstance(): void
    {
        $validator = new RouteConstraintValidator();
        $validator->addCustom('digit3', '/^\d{3}$/');
        $router = new Router($validator);
        $router->add('GET', '/code/{c:digit3}', 'code.handler');

        $result = $router->match('GET', '/code/123');
        $this->assertSame(['c' => '123'], $result['params']);

        $this->expectException(RouteConstraintException::class);
        $router->match('GET', '/code/12');
    }

    public function testMatchUuidConstraint(): void
    {
        $this->router->add('GET', '/u/{id:uuid}', 'u.handler');

        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $result = $this->router->match('GET', '/u/' . $uuid);
        $this->assertSame(['id' => $uuid], $result['params']);
    }

    public function testRouteEntryShape(): void
    {
        $this->router->add('PUT', '/api/{v:uint}/items/{name}', 'svc', 'mod', 7);

        $routes = $this->router->getRoutes();
        $entry = $routes[0];

        $this->assertSame('PUT', $entry['method']);
        $this->assertSame('/api/{v:uint}/items/{name}', $entry['pattern']);
        $this->assertSame('svc', $entry['handler']);
        $this->assertSame('mod', $entry['module']);
        $this->assertSame(7, $entry['priority']);
        $this->assertSame(2, $entry['staticCount']);
        $this->assertSame(1, $entry['constrainedCount']);
        $this->assertSame(0, $entry['sequence']);
    }
}
