<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Router\RadixIndex;
use Zef\Framework\Router\RoutePatternParser;
use Zef\Framework\Validation\RouteConstraintValidator;

final class RadixIndexTest extends TestCase
{
    /** @var list<array{method:string,pattern:string,handler:string,module:?string,priority:int,sequence:int,segments:list<array{dynamic:bool,name?:string,constraint?:string|null,value?:string}>,signature:string,staticCount:int,constrainedCount:int}> */
    private array $routes = [];

    private RoutePatternParser $parser;

    #[Override]
    protected function setUp(): void
    {
        $this->parser = new RoutePatternParser();
        $this->routes = array_merge(
            $this->route('GET', '/', 'home'),
            $this->route('GET', '/users', 'users.index'),
            $this->route('GET', '/users/{id:int}', 'users.show'),
            $this->route('GET', '/users/{id:int}/posts', 'users.posts'),
            $this->route('POST', '/users', 'users.store'),
        );
    }

    /**
     * @return array<int, array{
     *   method:string, pattern:string, handler:string, module:?string,
     *   priority:int, sequence:int,
     *   segments:list<array{dynamic:bool,name?:string,constraint?:string|null,value?:string}>,
     *   signature:string, staticCount:int, constrainedCount:int
     * }>
     */
    private function route(string $method, string $pattern, string $handler): array
    {
        $segments = $this->parser->parse($pattern);
        $staticCount = 0;
        $constrainedCount = 0;
        foreach ($segments as $segment) {
            if (!$segment['dynamic']) {
                $staticCount++;
            } elseif (($segment['constraint'] ?? null) !== null) {
                $constrainedCount++;
            }
        }

        return [
            [
                'method' => $method,
                'pattern' => $pattern,
                'handler' => $handler,
                'module' => null,
                'priority' => 0,
                'sequence' => 0,
                'segments' => $segments,
                'signature' => $method . '|' . $pattern,
                'staticCount' => $staticCount,
                'constrainedCount' => $constrainedCount,
            ],
        ];
    }

    public function testCompileBuildsSharedStaticSubtrees(): void
    {
        $radix = (new RadixIndex($this->parser))->compile($this->routes);

        $this->assertGreaterThanOrEqual(4, count($radix));
        $this->assertArrayHasKey('users', $radix[0]['static']);
        $this->assertSame([], $radix[0]['dynamic']);
        // Route '/' terminates at the root node.
        $this->assertSame([0], $radix[0]['routes']);
        // "/users" static edge must exist from root.
        $this->assertSame(1, $radix[0]['static']['users']);
    }

    public function testCompileThrowsWhenStaticSegmentHasNoStringValue(): void
    {
        $compile = new \ReflectionMethod(RadixIndex::class, 'compile');

        $this->expectException(\LogicException::class);
        $compile->invoke(new RadixIndex($this->parser), [
            [
                'method' => 'GET',
                'pattern' => '/x',
                'handler' => 'h',
                'module' => null,
                'priority' => 0,
                'sequence' => 0,
                'segments' => [['dynamic' => false, 'value' => 123]],
                'signature' => 'GET|/x',
                'staticCount' => 0,
                'constrainedCount' => 0,
            ],
        ]);
    }

    public function testMatchingCandidatesFindsTerminalRoutes(): void
    {
        $radix = (new RadixIndex($this->parser))->compile($this->routes);
        $constraints = new RouteConstraintValidator();

        $this->assertSame([1, 4], (new RadixIndex($this->parser))->matchingCandidates($radix, '/users', $constraints));
    }

    public function testMatchingCandidatesResolvesDynamicSegments(): void
    {
        $radix = (new RadixIndex($this->parser))->compile($this->routes);
        $constraints = new RouteConstraintValidator();

        $this->assertSame([2], (new RadixIndex($this->parser))->matchingCandidates($radix, '/users/42', $constraints));
    }

    public function testMatchingCandidatesPrunesConstraintViolations(): void
    {
        $radix = (new RadixIndex($this->parser))->compile($this->routes);
        $constraints = new RouteConstraintValidator();

        $this->assertSame([], (new RadixIndex($this->parser))->matchingCandidates($radix, '/users/not-a-number', $constraints));
    }

    public function testMatchingCandidatesReturnsEmptyForUnknownPath(): void
    {
        $radix = (new RadixIndex($this->parser))->compile($this->routes);
        $constraints = new RouteConstraintValidator();

        $this->assertSame([], (new RadixIndex($this->parser))->matchingCandidates($radix, '/nope', $constraints));
    }

    public function testStructuralCandidatesIgnoreConstraints(): void
    {
        $radix = (new RadixIndex($this->parser))->compile($this->routes);

        // Structural candidate set keeps constraint-violating dynamic edges,
        // so the 400-class path can distinguish constraint failures from 404s.
        $this->assertSame([2], (new RadixIndex($this->parser))->structuralCandidates($radix, '/users/not-a-number'));
        $this->assertSame([3], (new RadixIndex($this->parser))->structuralCandidates($radix, '/users/not-a-number/posts'));
    }

    public function testStructuralCandidatesReturnEmptyForUnknownPath(): void
    {
        $radix = (new RadixIndex($this->parser))->compile($this->routes);

        $this->assertSame([], (new RadixIndex($this->parser))->structuralCandidates($radix, '/nope'));
    }
}
