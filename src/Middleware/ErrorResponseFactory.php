<?php

declare(strict_types=1);

namespace Zef\Middleware {
    use Zef\Framework\Http\Response;

    /**
     * Builds JSON error responses. In production (devMode=false, the default
     * used when ZEF_DEBUG=0) exception messages are never exposed to clients:
     * only a generic 'An error occurred' body is emitted. Debug detail is
     * emitted exclusively when the application is booted with debug=true.
     */
    final class ErrorResponseFactory
    {
        public function __construct(private readonly bool $devMode = false)
        {
        }
        public function isDebug(): bool
        {
            return $this->devMode;
        }
        public function create(int $status, string $message, string $correlationId): Response
        {
            return new Response($status, ['Content-Type' => 'application/json'], json_encode(['error' => true,'status' => $status,'message' => $this->devMode ? $message : 'An error occurred','correlation_id' => $correlationId], JSON_THROW_ON_ERROR));
        }
    }
}
