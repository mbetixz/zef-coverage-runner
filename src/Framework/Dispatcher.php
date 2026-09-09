<?php

declare(strict_types=1);

namespace Zef\Framework {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Container\Container;
    use Zef\Framework\Exception\InvalidConfigurationException;
    use Zef\Framework\Exception\MethodNotAllowedException;
    use Zef\Framework\Exception\RouteConstraintException;
    use Zef\Framework\Exception\RouteNotFoundException;
    use Zef\Framework\Http\Response;
    use Zef\Framework\Router\Router;

    final class Dispatcher implements RequestHandlerInterface
    {
        public function __construct(private readonly Router $router, private readonly Container $container)
        {
        }
        #[\Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            /** @var \Zef\Framework\Observability\Telemetry $telemetry */
            $telemetry = $this->container->get(\Zef\Framework\Observability\Telemetry::class);
            $parent = $request->getAttribute('__zef_telemetry_span');
            $parentContext = $parent instanceof \Zef\Framework\Observability\SpanInterface ? $parent->getContext() : null;
            $routerSpan = $telemetry->startSpan('zef.router.match', ['http.request.method' => $request->getMethod(),'url.path' => $request->getUri()->getPath()], $parentContext);
            $started = hrtime(true);
            try {
                $match = $this->router->match($request->getMethod(), $request->getUri()->getPath());
                $routePattern = $match['pattern'];
                $routerSpan->setAttribute('http.route', $routePattern)->setStatus('OK');
                foreach ($match['params'] as $key => $value) {
                    $request = $request->withAttribute($key, $value);
                }
                $scope = $request->getAttribute('__zef_request_scope');
                $resolver = $scope instanceof \Zef\Framework\Container\RequestScope ? $scope : $this->container;
                $resolveStart = hrtime(true);
                $handler = $resolver->get($match['handler']);
                $telemetry->meter()->observe('zef.container.resolve.duration_seconds', (hrtime(true) - $resolveStart) / 1_000_000_000, ['zef.service.id' => $match['handler']]);
                if (!$handler instanceof RequestHandlerInterface) {
                    throw new InvalidConfigurationException("Handler '{$match['handler']}' does not implement RequestHandlerInterface.");
                }
                $handlerSpan = $telemetry->startSpan('zef.handler.execute', ['zef.handler' => $match['handler'],'http.route' => $match['pattern']], $parentContext);
                try {
                    $response = $handler->handle($request);
                    $handlerSpan->setAttribute('http.response.status_code', $response->getStatusCode())->setStatus($response->getStatusCode() >= 500 ? 'ERROR' : 'OK');
                    return $response;
                } catch (\Throwable $e) {
                    $handlerSpan->setStatus('ERROR', $e::class)->addEvent('exception', ['exception.type' => $e::class]);
                    throw $e;
                } finally {
                    $handlerSpan->end();
                }
            } catch (RouteNotFoundException) {
                return new Response(404, ['Content-Type' => 'application/json'], json_encode(['error' => 'Not Found','method' => $request->getMethod(),'path' => $request->getUri()->getPath()], JSON_THROW_ON_ERROR));
            } catch (MethodNotAllowedException $e) {
                return (new Response(405, ['Allow' => implode(', ', $e->allowedMethods),'Content-Type' => 'application/json'], json_encode(['error' => 'Method Not Allowed','method' => $e->method,'path' => $e->path,'allow' => $e->allowedMethods], JSON_THROW_ON_ERROR)));
            } catch (RouteConstraintException $e) {
                return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'Bad Request','detail' => $e->getMessage()], JSON_THROW_ON_ERROR));
            } finally {
                $routerSpan->setAttribute('zef.router.duration_seconds', (hrtime(true) - $started) / 1_000_000_000);
                $routerSpan->end();
            }
        }
    }
}
