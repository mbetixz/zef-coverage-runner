<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Application;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Runtime\InMemoryWorker;
use Zef\Framework\Runtime\RoadRunnerRuntime;
use Zef\Framework\Runtime\WorkerInterface;

/**
 * Batch 10 coverage: RoadRunnerRuntime (previously 0% - one of the largest
 * uncovered production surfaces). Exercises the single-use lifecycle: main
 * dispatch loop, worker exhaustion, max-jobs drain, memory guard exit code,
 * worker transport failure and handler failure 500 path, plus constructor
 * and ZEF_RUNTIME_* configuration validation.
 *
 * Determinism: telemetry disabled (ZEF_OTEL_ENABLED=0), signal handlers not
 * installed (pcntl-independent), no sleeps or wall-clock assertions, all
 * runtime env restored in tearDown.
 */
final class Batch10RoadRunnerRuntimeTest extends TestCase
{
    private const array RUNTIME_ENV = [
        'ZEF_RUNTIME_RESOURCE_CAPACITY',
        'ZEF_RUNTIME_SATURATION_PERCENT',
        'ZEF_RUNTIME_CONTROL_PLANE',
        'ZEF_OTEL_ENABLED',
        'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT',
        'ZEF_OTEL_SHUTDOWN_DRAIN_MS',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::RUNTIME_ENV as $name) {
            putenv($name);
        }
        putenv('ZEF_OTEL_ENABLED=0');
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (self::RUNTIME_ENV as $name) {
            putenv($name);
        }
        parent::tearDown();
    }

    /** Build an Application with two static routes (/run and /boom) over one handler. */
    private function app(): Application
    {
        $provider = new class implements ConfigProviderInterface {
            #[\Override]
            public function getModuleName(): string
            {
                return 'rtmod';
            }

            #[\Override]
            public function getConfig(): array
            {
                return [
                    'services' => [
                        'rt.handler' => [
                            'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                                #[\Override]
                                public function handle(ServerRequestInterface $request): ResponseInterface
                                {
                                    if ($request->getUri()->getPath() === '/boom') {
                                        throw new \RuntimeException('handler exploded');
                                    }
                                    return new Response(200, ['Content-Type' => 'text/plain'], 'ok');
                                }
                            },
                        ],
                    ],
                    'aliases' => [],
                    'routes' => [
                        ['method' => 'GET', 'path' => '/run', 'handler' => 'rt.handler', 'priority' => 100],
                        ['method' => 'GET', 'path' => '/boom', 'handler' => 'rt.handler', 'priority' => 100],
                    ],
                    'middleware.stack' => [],
                ];
            }
        };
        $application = new Application();
        $application->addProvider($provider);
        return $application;
    }

    private function request(string $path = '/run'): ServerRequestInterface
    {
        return new ServerRequest('GET', new Uri('http://localhost' . $path, ['localhost']));
    }

    private function runtime(
        Application $application,
        WorkerInterface $worker,
        int $maxJobs = 0,
        int $memoryLimitBytes = 0,
    ): RoadRunnerRuntime {
        return new RoadRunnerRuntime($application, $worker, $maxJobs, $memoryLimitBytes, false);
    }

    public function testConstructorRejectsNegativeMaxJobs(): void
    {
        try {
            $this->runtime($this->app(), new InMemoryWorker([]), -1);
            self::fail('negative maxJobs must be rejected');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('maxJobs', $e->getMessage());
        }
    }

    public function testConstructorRejectsNegativeMemoryLimit(): void
    {
        try {
            $this->runtime($this->app(), new InMemoryWorker([]), 0, -1);
            self::fail('negative memory limit must be rejected');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('memoryLimitBytes', $e->getMessage());
        }
    }

    public function testRunEmptyWorkerExitsZero(): void
    {
        $runtime = $this->runtime($this->app(), new InMemoryWorker([]));
        self::assertSame(0, $runtime->run());
        self::assertSame(0, $runtime->handledRequests());
        self::assertFalse($runtime->isRunning());
    }

    public function testRunHandlesRequestAndResponds(): void
    {
        $worker = new InMemoryWorker([$this->request('/run')]);
        $runtime = $this->runtime($this->app(), $worker);
        self::assertSame(0, $runtime->run());
        self::assertSame(1, $runtime->handledRequests());
        self::assertCount(1, $worker->responses());
        self::assertSame(200, $worker->responses()[0]->getStatusCode());
    }

    public function testRunStopsCleanlyAfterMaxJobs(): void
    {
        $worker = new InMemoryWorker([$this->request('/run'), $this->request('/run')]);
        $runtime = $this->runtime($this->app(), $worker, 1);
        self::assertSame(0, $runtime->run());
        self::assertSame(1, $runtime->handledRequests());
        self::assertCount(1, $worker->responses());
    }

    public function testRunMemoryGuardExitsTwo(): void
    {
        $worker = new InMemoryWorker([$this->request('/run')]);
        // A 1-byte ceiling guarantees the guard trips before any request is served.
        $runtime = $this->runtime($this->app(), $worker, 0, 1);
        self::assertSame(2, $runtime->run());
        self::assertSame(0, $runtime->handledRequests());
        self::assertCount(0, $worker->responses());
    }

    public function testRunWorkerWaitFailureExitsOne(): void
    {
        $worker = new class implements WorkerInterface {
            public int $errors = 0;

            #[\Override]
            public function waitRequest(): ?ServerRequestInterface
            {
                throw new \RuntimeException('transport down');
            }

            #[\Override]
            public function respond(ResponseInterface $response): void
            {
            }

            #[\Override]
            public function error(string $message): void
            {
                ++$this->errors;
            }

            #[\Override]
            public function stop(): void
            {
            }

            #[\Override]
            public function isRunning(): bool
            {
                return true;
            }
        };
        $runtime = $this->runtime($this->app(), $worker);
        self::assertSame(1, $runtime->run());
        self::assertSame(1, $worker->errors);
    }

    public function testRunHandlerFailureResponds500AndKeepsLoopAlive(): void
    {
        $worker = new InMemoryWorker([$this->request('/boom')]);
        $runtime = $this->runtime($this->app(), $worker);
        self::assertSame(1, $runtime->run());
        self::assertSame(1, $runtime->handledRequests());
        self::assertCount(1, $worker->responses());
        self::assertSame(500, $worker->responses()[0]->getStatusCode());
    }

    public function testRunWithConfiguredCapacityAndControlPlane(): void
    {
        putenv('ZEF_RUNTIME_RESOURCE_CAPACITY=2');
        putenv('ZEF_RUNTIME_SATURATION_PERCENT=80');
        putenv('ZEF_RUNTIME_CONTROL_PLANE=on');
        $worker = new InMemoryWorker([$this->request('/run'), $this->request('/run')]);
        $runtime = $this->runtime($this->app(), $worker);
        self::assertSame(0, $runtime->run());
        self::assertSame(2, $runtime->handledRequests());
        self::assertCount(2, $worker->responses());
    }

    public function testInvalidResourceCapacityEnvRejected(): void
    {
        foreach (['0', '2000', 'not-a-number'] as $raw) {
            putenv('ZEF_RUNTIME_RESOURCE_CAPACITY=' . $raw);
            try {
                $this->runtime($this->app(), new InMemoryWorker([]));
                self::fail("capacity '{$raw}' must be rejected");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('ZEF_RUNTIME_RESOURCE_CAPACITY', $e->getMessage());
            }
        }
    }

    public function testInvalidSaturationEnvRejected(): void
    {
        foreach (['10', '100', 'x'] as $raw) {
            putenv('ZEF_RUNTIME_SATURATION_PERCENT=' . $raw);
            try {
                $this->runtime($this->app(), new InMemoryWorker([]));
                self::fail("saturation '{$raw}' must be rejected");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('ZEF_RUNTIME_SATURATION_PERCENT', $e->getMessage());
            }
        }
    }

    public function testInvalidControlPlaneEnvRejected(): void
    {
        putenv('ZEF_RUNTIME_CONTROL_PLANE=yes');
        try {
            $this->runtime($this->app(), new InMemoryWorker([]));
            self::fail('invalid control plane value must be rejected');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('ZEF_RUNTIME_CONTROL_PLANE', $e->getMessage());
        }
    }
}
