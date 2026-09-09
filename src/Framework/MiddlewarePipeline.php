<?php

declare(strict_types=1);

namespace Zef\Framework {

    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\MiddlewareInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Http\Response;

    final class MiddlewarePipeline implements RequestHandlerInterface
    {
        /**
         * @param list<MiddlewareInterface> $stack
         */
        public function __construct(private readonly array $stack = [], private readonly ?RequestHandlerInterface $terminal = null, private readonly int $index = 0)
        {
        }
        public function withMiddleware(MiddlewareInterface $middleware): self
        {
            $s = $this->stack;
            $s[] = $middleware;
            return new self($s, $this->terminal, $this->index);
        }
        #[\Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            if ($this->index >= count($this->stack)) {
                if ($this->terminal === null) {
                    return new Response(500, ['Content-Type' => 'text/plain'], 'Pipeline terminal missing.');
                }return $this->terminal->handle($request);
            }
            $middleware = $this->stack[$this->index];
            $next = new self($this->stack, $this->terminal, $this->index + 1);
            return $middleware->process($request, $next);
        }
    }
}

namespace {
    require_once __DIR__ . '/PipelineFactory.php';
}
