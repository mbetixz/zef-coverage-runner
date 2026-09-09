<?php

declare(strict_types=1);

namespace Zef\Middleware {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\MiddlewareInterface;
    use Psr\Http\Server\RequestHandlerInterface;

    /**
     * Adds an X-Response-Time header (elapsed handling time in ms, 2 decimals)
     * to every response. The value is generated locally from hrtime() so it is
     * never derived from request input and needs no additional sanitization.
     */
    final class TimingMiddleware implements MiddlewareInterface
    {
        #[\Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $startNs = hrtime(true);
            $response = $handler->handle($request);
            $elapsedNs = hrtime(true) - $startNs;
            $elapsedMs = round($elapsedNs / 1_000_000, 2);

            return $response->withHeader('X-Response-Time', $elapsedMs . 'ms');
        }
    }
}
