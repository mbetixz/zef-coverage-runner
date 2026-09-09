<?php

declare(strict_types=1);

namespace Zef\Plugin\Toko {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Http\Response;

    final class TokoHandler implements RequestHandlerInterface
    {
        public function __construct(private readonly ProdukService $service)
        {
        }

        #[\Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode(['module' => 'toko','page' => 'index','produk' => $this->service->all()], JSON_THROW_ON_ERROR));
        }
    }
}
