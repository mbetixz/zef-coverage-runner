<?php

declare(strict_types=1);

namespace Zef\Middleware {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\MiddlewareInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Http\Response;

    /**
     * Ensures every response carries a validated correlation id.
     *
     * The inbound X-Request-ID is accepted only when it is 1-128 chars of
     * [A-Za-z0-9._:-]; anything else (absent, over-long, or containing CR/LF
     * or other disallowed bytes) is replaced by a fresh 32-hex id so the
     * response header can never echo attacker-controlled input. The same id
     * is attached to error responses (405/500) and used for log correlation.
     */
    final class GlobalErrorHandler implements MiddlewareInterface
    {
        public function __construct(private readonly \Psr\Log\LoggerInterface $logger, private readonly ErrorResponseFactory $factory)
        {
        }
        #[\Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $correlationId = $request->getHeaderLine('X-Request-ID');
            if ($correlationId === '' || strlen($correlationId) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $correlationId) !== 1) {
                $correlationId = bin2hex(random_bytes(16));
            }
            try {
                return $handler->handle($request)->withHeader('X-Request-ID', $correlationId);
            } catch (\Zef\Framework\Exception\MethodNotAllowedException $e) {
                return $this->factory->create(405, 'Method Not Allowed', $correlationId)->withHeader('Allow', implode(', ', $e->allowedMethods))->withHeader('X-Request-ID', $correlationId);
            } catch (\Throwable $e) {
                try {
                    $this->logger->error('Unhandled application exception', [
                        'exception' => $e,
                        'exception.message' => \Zef\Framework\Observability\TelemetrySanitizer::redact($e->getMessage()),
                        'request_id' => $correlationId,
                        'method' => $request->getMethod(),
                        'path' => $request->getUri()->getPath(),
                    ]);
                } catch (\Throwable $loggingFailure) {
                    @error_log('ZEF logging failure: ' . get_class($loggingFailure));
                }
                try {
                    return $this->factory->create(500, $this->factory->isDebug() ? $e->getMessage() : 'Internal Server Error', $correlationId)->withHeader('X-Request-ID', $correlationId);
                } catch (\Throwable) {
                    return new Response(500, ['Content-Type' => 'text/plain','X-Request-ID' => $correlationId], 'Internal Server Error');
                }
            }
        }
    }
}
