<?php

declare(strict_types=1);

namespace Zef\Plugin\Toko {
    use Zef\Framework\Config\ConfigProviderInterface;
    use Zef\Framework\Container\ServiceLifetime;

    final class ConfigProvider implements ConfigProviderInterface
    {
        #[\Override]
        public function getModuleName(): string
        {
            return 'toko';
        }

        #[\Override]
        public function getConfig(): array
        {
            return ['services' => ['toko.service.produk' => ['factory' => static fn () => new ProdukService(),'deps' => [],'lifetime' => ServiceLifetime::SINGLETON],'toko.handler.index' => ['factory' => static fn (\Psr\Container\ContainerInterface $c, ProdukService $svc) => new TokoHandler($svc),'deps' => ['toko.service.produk'],'lifetime' => ServiceLifetime::SINGLETON],'toko.handler.detail' => ['factory' => static fn (\Psr\Container\ContainerInterface $c, ProdukService $svc) => new ProdukDetailHandler($svc),'deps' => ['toko.service.produk'],'lifetime' => ServiceLifetime::SINGLETON]],'aliases' => ['toko.produk' => 'toko.service.produk'],'routes' => [['method' => 'GET','path' => '/toko','handler' => 'toko.handler.index','priority' => 100],['method' => 'GET','path' => '/toko/produk/{id:int}','handler' => 'toko.handler.detail','priority' => 100]]];
        }
    }
}
