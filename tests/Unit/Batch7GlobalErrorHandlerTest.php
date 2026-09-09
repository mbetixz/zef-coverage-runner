<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Http\Response;
use Zef\Middleware\ErrorResponseFactory;
use Zef\Middleware\GlobalErrorHandler;

/**
 * Coverage for GlobalErrorHandler (correlation-id normalization, 405/500
 * mapping, logging-failure resilience) and ErrorResponseFactory.
 * Deterministic: fixed request inputs; no wall-clock dependence.
 */
final class Batch7GlobalErrorHandlerTest extends TestCase
{
    private function request(string $method = 'GET', string $requestId = '', string $path = '/x'): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn($requestId);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        return $request;
    }

    private function handler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        return $handler;
    }

    /**
     * @param array<int, array{message: string, context: array<mixed>}> $records
     * @return array<string, mixed>
     */
    private function record(array &$records, int $index): array
    {
        if (!isset($records[$index])) {
            return [];
        }
        /** @var array<string, mixed> $typed */
        $typed = $records[$index]['context'];
        return $typed;
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body)) {
            self::fail('response body must be a JSON object');
        }
        /** @var array<string, mixed> $typed */
        $typed = $body;
        return $typed;
    }

    public function testValidInboundRequestIdIsPreserved(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $handler = $this->handler((new Response(200, [], 'ok'))->withHeader('X-Request-ID', 'ignored'));
        $response = (new GlobalErrorHandler($logger, new ErrorResponseFactory()))
            ->process($this->request('GET', 'req-123.abc_xyz:-456'), $handler);
        self::assertSame('req-123.abc_xyz:-456', $response->getHeaderLine('X-Request-ID'));
    }

    public function testInvalidRequestIdIsReplacedWithFreshHex(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $handler = $this->handler(new Response(200, [], 'ok'));
        $bad = ["bad id with spaces", '', "crlf\r\ninjected", str_repeat('x', 129), "semi;colon", 'unicode–dash'];
        foreach ($bad as $value) {
            $response = (new GlobalErrorHandler($logger, new ErrorResponseFactory()))
                ->process($this->request('GET', $value), $handler);
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $response->getHeaderLine('X-Request-ID'), "value '{$value}'");
        }
    }

    public function testMethodNotAllowedMapsTo405WithAllowHeader(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new MethodNotAllowedException('PATCH', '/x', ['GET', 'POST']));

        $response = (new GlobalErrorHandler($logger, new ErrorResponseFactory()))
            ->process($this->request('PATCH', 'req-1'), $handler);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->getHeaderLine('Allow'));
        self::assertSame('req-1', $response->getHeaderLine('X-Request-ID'));
    }

    public function testGenericExceptionLogsAndReturns500Production(): void
    {
        $records = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            static function (string $message, array $context = []) use (&$records): void {
                $records[] = ['message' => $message, 'context' => $context];
            },
        );
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new RuntimeException('boom detail'));

        $response = (new GlobalErrorHandler($logger, new ErrorResponseFactory()))
            ->process($this->request('POST', 'req-2'), $handler);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('req-2', $response->getHeaderLine('X-Request-ID'));
        self::assertCount(1, $records);
        $context = $this->record($records, 0);
        $exceptionMessage = $context['exception.message'] ?? null;
        self::assertIsString($exceptionMessage);
        self::assertStringContainsString('boom', $exceptionMessage);
        self::assertArrayHasKey('request_id', $context);
        $body = $this->decode($response);
        self::assertTrue($body['error']);
        self::assertSame('An error occurred', $body['message']);
    }

    public function testDebugModeExposesExceptionMessage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new RuntimeException('debug detail'));

        $response = (new GlobalErrorHandler($logger, new ErrorResponseFactory(devMode: true)))
            ->process($this->request('GET', 'req-3'), $handler);
        self::assertSame(500, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('debug detail', $body['message']);
    }

    public function testLoggingFailureIsSwallowedAndResponseStillBuilt(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willThrowException(new RuntimeException('logger down'));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new RuntimeException('app crash'));

        $response = (new GlobalErrorHandler($logger, new ErrorResponseFactory()))
            ->process($this->request('GET', 'req-4'), $handler);
        self::assertSame(500, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('An error occurred', $body['message']);
    }

    public function testExceptionPathReturns500WithCorrelationId(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new RuntimeException('app crash'));

        $response = (new GlobalErrorHandler($logger, new ErrorResponseFactory()))
            ->process($this->request('GET', 'req-5'), $handler);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('req-5', $response->getHeaderLine('X-Request-ID'));
        $body = $this->decode($response);
        self::assertSame('An error occurred', $body['message']);
    }

    // --- ErrorResponseFactory -------------------------------------------------

    public function testFactoryProductionHidesMessage(): void
    {
        $factory = new ErrorResponseFactory();
        self::assertFalse($factory->isDebug());
        $response = $factory->create(400, 'secret internal', 'corr-1');
        self::assertSame(400, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('An error occurred', $body['message']);
        self::assertSame('corr-1', $body['correlation_id']);
        self::assertTrue($body['error']);
    }

    public function testFactoryDebugExposesMessage(): void
    {
        $factory = new ErrorResponseFactory(devMode: true);
        self::assertTrue($factory->isDebug());
        $body = $this->decode($factory->create(503, 'maintenance', 'corr-2'));
        self::assertSame('maintenance', $body['message']);
        self::assertSame(503, $body['status']);
    }
}
