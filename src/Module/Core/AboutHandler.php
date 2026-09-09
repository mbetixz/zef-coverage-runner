<?php

declare(strict_types=1);

namespace Zef\Module\Core {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Http\Response;

    final class AboutHandler implements RequestHandlerInterface
    {
        #[\Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode(['module' => 'core','page' => 'about','version' => \Zef\Framework\Foundation\ZefVersion::VERSION,'php' => PHP_VERSION,'license' => 'MIT'], JSON_THROW_ON_ERROR));
        }
    }
}
