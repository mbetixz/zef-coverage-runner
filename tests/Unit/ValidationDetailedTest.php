<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\InvalidHeaderException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Validation\DependencyGraphValidator;
use Zef\Framework\Validation\HeaderValidator;
use Zef\Framework\Validation\HttpMethodValidator;
use Zef\Framework\Validation\HttpStatusValidator;
use Zef\Framework\Validation\PortRangeValidator;
use Zef\Framework\Validation\RouteConstraintValidator;
use Zef\Framework\Validation\TrustedHostValidator;

final class ValidationDetailedTest extends TestCase
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

    public function testRouteConstraintBuiltins(): void
    {
        $v = new RouteConstraintValidator();

        self::assertTrue($v->test('id', 'int', '42'));
        self::assertTrue($v->test('id', 'int', '0'));
        self::assertFalse($v->test('id', 'int', ''));
        self::assertFalse($v->test('id', 'int', '42a'));
        self::assertFalse($v->test('id', 'int', '-1'));

        self::assertTrue($v->test('id', 'uint', '42'));
        self::assertFalse($v->test('id', 'uint', '0'));
        self::assertFalse($v->test('id', 'uint', '042'));

        self::assertTrue($v->test('s', 'alpha', 'AbcZ'));
        self::assertFalse($v->test('s', 'alpha', 'abc1'));
        self::assertFalse($v->test('s', 'alpha', ''));

        self::assertTrue($v->test('s', 'slug', 'hello-world'));
        self::assertTrue($v->test('s', 'slug', 'a0'));
        self::assertFalse($v->test('s', 'slug', 'Hello'));
        self::assertFalse($v->test('s', 'slug', '-lead'));
        self::assertFalse($v->test('s', 'slug', 'trail-'));
        self::assertFalse($v->test('s', 'slug', ''));

        self::assertTrue($v->test('id', 'uuid', '123e4567-e89b-42d3-a456-426614174000'));
        self::assertFalse($v->test('id', 'uuid', '123e4567'));
        self::assertFalse($v->test('id', 'uuid', ''));

        self::assertTrue($v->test('h', 'hex', 'deadBEEF'));
        self::assertTrue($v->test('h', 'hex', '0a1f'));
        self::assertFalse($v->test('h', 'hex', 'zz'));
        self::assertFalse($v->test('h', 'hex', ''));
    }

    public function testRouteConstraintUnknownTypeThrows(): void
    {
        $v = new RouteConstraintValidator();
        self::assertThrows(InvalidConfigurationException::class, static fn () => $v->test('id', 'nope', '1'));
        self::assertThrows(InvalidConfigurationException::class, static fn () => $v->assertKnown('nope'));
        $v->assertKnown('int');
        $v->assertKnown('uint');
        $v->assertKnown('alpha');
        $v->assertKnown('slug');
        $v->assertKnown('uuid');
        $v->assertKnown('hex');
    }

    public function testRouteConstraintCustom(): void
    {
        $v = new RouteConstraintValidator();
        $v->addCustom('even', '/^\d*[02468]$/');
        self::assertTrue($v->test('n', 'even', '246'));
        self::assertFalse($v->test('n', 'even', '123'));

        // Custom overwrite invalidates the compiled matcher.
        $v->addCustom('even', '/^[0-9]+$/');
        self::assertTrue($v->test('n', 'even', '123'));
    }

    public function testRouteConstraintInvalidCustomNameThrows(): void
    {
        $v = new RouteConstraintValidator();
        self::assertThrows(InvalidConfigurationException::class, static fn () => $v->addCustom('bad name', '/x/'));
        self::assertThrows(InvalidConfigurationException::class, static fn () => $v->addCustom('', '/x/'));
    }

    public function testRouteConstraintInvalidRegexThrows(): void
    {
        $v = new RouteConstraintValidator();
        // Unbalanced delimiter -> preg_match emits warning, converted to ErrorException.
        self::assertThrows(InvalidConfigurationException::class, static fn () => $v->addCustom('bad', '/unclosed'));
        // Overlong pattern rejected by policy.
        self::assertThrows(
            InvalidConfigurationException::class,
            static fn () => $v->addCustom('long', str_repeat('a', 2049)),
        );
        // ReDoS policy: nested quantified group.
        self::assertThrows(
            InvalidConfigurationException::class,
            static fn () => $v->addCustom('evil', '/(a+)+$/'),
        );
        // Empty pattern rejected.
        self::assertThrows(InvalidConfigurationException::class, static fn () => $v->addCustom('empty', ''));
    }

    public function testRouteConstraintAssertAndEvaluationFailure(): void
    {
        $v = new RouteConstraintValidator();
        self::assertThrows(RouteConstraintException::class, static fn () => $v->assert('id', 'int', 'abc'));
        $v->assert('id', 'int', '123');
    }

    public function testDependencyGraphValidatorHappyPathAndAliases(): void
    {
        $g = new DependencyGraphValidator();
        $g->validate(
            ['a' => fn () => null, 'b' => fn () => null, 'c' => fn () => null],
            ['b2' => 'b'],
            ['a' => ['b2'], 'b' => ['c'], 'c' => []],
            ['a' => 'm1', 'b' => 'm1', 'c' => 'm2'],
            ['a' => 'singleton', 'b' => 'singleton', 'c' => 'singleton'],
            0,
        );
        self::assertSame('b', $g->resolveAlias('b2', ['b2' => 'b']));
        self::assertSame('plain', $g->resolveAlias('plain', []));
    }

    public function testDependencyGraphValidatorMissingServiceThrows(): void
    {
        $g = new DependencyGraphValidator();
        self::assertThrows(
            \Zef\Framework\Exception\ServiceNotFoundException::class,
            static fn () => $g->validate(['a' => fn () => null], [], ['a' => ['ghost']], ['a' => 'mod'], []),
        );
        // Alias resolving to nothing.
        self::assertThrows(
            \Zef\Framework\Exception\ServiceNotFoundException::class,
            static fn () => $g->validate(['a' => fn () => null], ['x' => 'ghost'], [], [], []),
        );
    }

    public function testDependencyGraphValidatorCycleThrows(): void
    {
        $g = new DependencyGraphValidator();
        self::assertThrows(
            \Zef\Framework\Exception\ServiceCircularDependencyException::class,
            static fn () => $g->validate(
                ['a' => fn () => null, 'b' => fn () => null],
                [],
                ['a' => ['b'], 'b' => ['a']],
                [],
                [],
            ),
        );
    }

    public function testDependencyGraphValidatorCircularAliasThrows(): void
    {
        $g = new DependencyGraphValidator();
        self::assertThrows(
            \Zef\Framework\Exception\CircularAliasException::class,
            static fn () => $g->validate(['a' => fn () => null], ['a' => 'b', 'b' => 'a'], [], [], []),
        );
    }

    public function testDependencyGraphValidatorEmptyAliasTargetThrows(): void
    {
        $g = new DependencyGraphValidator();
        self::assertThrows(
            InvalidConfigurationException::class,
            static fn () => $g->validate(['a' => fn () => null], ['x' => ''], ['a' => ['x']], [], []),
        );
    }

    public function testDependencyGraphValidatorSingletonClosureViolationThrows(): void
    {
        $g = new DependencyGraphValidator();
        // Singleton 'a' transitively depends on transient 'b' -> forbidden.
        self::assertThrows(
            InvalidConfigurationException::class,
            static fn () => $g->validate(
                ['a' => fn () => null, 'b' => fn () => null, 'c' => fn () => null],
                [],
                ['a' => ['b'], 'b' => ['c'], 'c' => []],
                [],
                ['a' => 'singleton', 'b' => 'singleton', 'c' => 'transient'],
            ),
        );
    }

    public function testDependencyGraphValidatorCrossModuleLimitThrows(): void
    {
        $g = new DependencyGraphValidator();
        // Two distinct edges from module m1 toward module m2 with limit 1.
        self::assertThrows(
            \Zef\Framework\Exception\ModuleDependencyViolationException::class,
            static fn () => $g->validate(
                ['a' => fn () => null, 'b' => fn () => null, 'c' => fn () => null, 'd' => fn () => null],
                [],
                ['a' => ['c'], 'b' => ['d'], 'c' => [], 'd' => []],
                ['a' => 'm1', 'b' => 'm1', 'c' => 'm2', 'd' => 'm2'],
                ['a' => 'singleton', 'b' => 'singleton', 'c' => 'singleton', 'd' => 'singleton'],
                1,
            ),
        );
    }

    public function testHttpMethodValidator(): void
    {
        HttpMethodValidator::assert('GET');
        HttpMethodValidator::assert('PATCH');
        HttpMethodValidator::assert('MKCOL');
        self::assertThrows(\InvalidArgumentException::class, static fn () => HttpMethodValidator::assert(''));
        self::assertThrows(\InvalidArgumentException::class, static fn () => HttpMethodValidator::assert("GE T"));
        self::assertThrows(\InvalidArgumentException::class, static fn () => HttpMethodValidator::assert("GET\r"));
    }

    public function testHttpStatusValidator(): void
    {
        $v = new HttpStatusValidator();
        $v->assert(100);
        $v->assert(200);
        $v->assert(404);
        $v->assert(599);
        self::assertThrows(\InvalidArgumentException::class, static fn () => $v->assert(99));
        self::assertThrows(\InvalidArgumentException::class, static fn () => $v->assert(600));
    }

    public function testPortRangeValidator(): void
    {
        $v = new PortRangeValidator();
        $v->assert(null);
        $v->assert(1);
        $v->assert(65535);
        $v->assert(8080);
        self::assertThrows(\InvalidArgumentException::class, static fn () => $v->assert(0));
        self::assertThrows(\InvalidArgumentException::class, static fn () => $v->assert(65536));
    }

    public function testHeaderValidator(): void
    {
        $v = new HeaderValidator();
        $v->assertName('X-Custom-Header');
        $v->assertValue('X-Test', 'plain value');
        self::assertThrows(InvalidHeaderException::class, static fn () => $v->assertName(''));
        self::assertThrows(InvalidHeaderException::class, static fn () => $v->assertName('Bad Header'));
        self::assertThrows(InvalidHeaderException::class, static fn () => $v->assertValue('X-Test', "a\r\nb"));
        self::assertThrows(InvalidHeaderException::class, static fn () => $v->assertValue('X-Test', "a\nb"));
        self::assertThrows(InvalidHeaderException::class, static fn () => $v->assertValue('X-Test', "a\x00b"));
    }

    public function testTrustedHostValidator(): void
    {
        $v = new TrustedHostValidator(['example.com', '[::1]']);
        $v->assert('example.com');
        $v->assert('EXAMPLE.COM');
        $v->assert(' example.com ');
        $v->assert('::1');
        self::assertThrows(\InvalidArgumentException::class, static fn () => $v->assert('evil.com'));
        // Empty host passes through (checked before trusted list) and an empty
        // trusted list disables enforcement entirely.
        (new TrustedHostValidator())->assert('');
        (new TrustedHostValidator())->assert('anything');
        (new TrustedHostValidator([]))->assert('also-anything');
        (new TrustedHostValidator(['ok.dev']))->assert('');
        self::assertThrows(\InvalidArgumentException::class, static fn () => (new TrustedHostValidator(['ok.dev']))->assert('other.dev'));
    }
}
