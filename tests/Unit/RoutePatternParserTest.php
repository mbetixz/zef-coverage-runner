<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Router\RoutePatternParser;

final class RoutePatternParserTest extends TestCase
{
    public function testParsesRootPatternToEmptySegments(): void
    {
        $parser = new RoutePatternParser();

        $this->assertSame([], $parser->parse('/'));
        $this->assertSame([], $parser->splitPath('/'));
    }

    public function testParsesStaticSegments(): void
    {
        $parser = new RoutePatternParser();

        $this->assertSame(
            [
                ['dynamic' => false, 'value' => 'users'],
                ['dynamic' => false, 'value' => 'list'],
            ],
            $parser->parse('/users/list'),
        );
    }

    public function testParsesDynamicSegmentWithNameOnly(): void
    {
        $parser = new RoutePatternParser();

        $this->assertSame(
            [
                ['dynamic' => true, 'name' => 'id', 'constraint' => null],
            ],
            $parser->parse('/{id}'),
        );
    }

    public function testParsesDynamicSegmentWithConstraint(): void
    {
        $parser = new RoutePatternParser();

        $this->assertSame(
            [
                ['dynamic' => true, 'name' => 'id', 'constraint' => 'int'],
            ],
            $parser->parse('/{id:int}'),
        );
    }

    public function testRejectsMalformedDynamicSegment(): void
    {
        $parser = new RoutePatternParser();

        $this->expectException(\InvalidArgumentException::class);
        $parser->parse('/users/{bad-name}');
    }

    public function testCanonicalSignature(): void
    {
        $parser = new RoutePatternParser();

        $segments = $parser->parse('/users/{id:int}/posts');
        $this->assertSame(
            'GET|/users/*int/posts',
            $parser->canonicalSignature('GET', $segments),
        );
    }

    public function testSplitPathTrimsLeadingAndTrailingSlashes(): void
    {
        $parser = new RoutePatternParser();

        $this->assertSame(['a', 'b'], $parser->splitPath('/a/b/'));
        $this->assertSame([], $parser->splitPath('/'));
    }
}
