<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Router\RouteMatcher;
use Zef\Framework\Router\RoutePatternParser;
use Zef\Framework\Validation\RouteConstraintValidator;

final class RouteMatcherTest extends TestCase
{
    private RouteMatcher $matcher;

    #[Override]
    protected function setUp(): void
    {
        $this->matcher = new RouteMatcher(
            new RoutePatternParser(),
            new RouteConstraintValidator(),
        );
    }

    public function testMatchesStaticSegmentsWithEmptyParams(): void
    {
        $segments = (new RoutePatternParser())->parse('/users/list');

        $this->assertSame([], $this->matcher->match($segments, '/users/list'));
    }

    public function testCapturesDynamicParams(): void
    {
        $segments = (new RoutePatternParser())->parse('/users/{id:int}/posts/{slug}');

        $this->assertSame(
            ['id' => '42', 'slug' => 'hello'],
            $this->matcher->match($segments, '/users/42/posts/hello'),
        );
    }

    public function testReturnsFalseOnSegmentCountMismatch(): void
    {
        $segments = (new RoutePatternParser())->parse('/users/{id}');

        $this->assertFalse($this->matcher->match($segments, '/users/42/posts'));
        $this->assertFalse($this->matcher->match($segments, '/users'));
    }

    public function testReturnsFalseOnStaticMismatch(): void
    {
        $segments = (new RoutePatternParser())->parse('/users/list');

        $this->assertFalse($this->matcher->match($segments, '/users/other'));
    }

    public function testReturnsExceptionOnConstraintViolation(): void
    {
        $segments = (new RoutePatternParser())->parse('/users/{id:int}');
        $result = $this->matcher->match($segments, '/users/not-a-number');

        $this->assertInstanceOf(RouteConstraintException::class, $result);
        $this->assertSame('id', $result->param);
        $this->assertSame('int', $result->type);
        $this->assertSame('not-a-number', $result->value);
    }

    public function testThrowsWhenDynamicSegmentMissingName(): void
    {
        $segments = [
            ['dynamic' => true, 'constraint' => null],
        ];

        $this->expectException(\LogicException::class);
        $this->matcher->match($segments, '/anything');
    }
}
