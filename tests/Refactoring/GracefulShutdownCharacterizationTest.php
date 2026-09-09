<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/vendor/autoload.php';

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zef\App\Bootstrap;
use Zef\Framework\Http\Response;
use Zef\Framework\Runtime\RoadRunnerRuntime;
use Zef\Framework\Runtime\WorkerInterface;

final class RF06GracefulShutdownWorker implements WorkerInterface
{
    /** @var list<ServerRequestInterface> */
    private array $requests;
    /** @var list<ResponseInterface> */
    private array $responses = [];
    /** @var list<string> */
    private array $errors = [];
    private bool $running = true;
    private bool $signalSent = false;

    /** @param list<ServerRequestInterface> $requests */
    public function __construct(array $requests)
    {
        $this->requests = $requests;
    }

    #[\Override]
    public function waitRequest(): ?ServerRequestInterface
    {
        if (!$this->signalSent) {
            $this->signalSent = true;
            usleep(10_000);
            $pid = getmypid();
            if ($pid === false) {
                throw new RuntimeException('Unable to identify current process.');
            }
            posix_kill($pid, SIGTERM);
            usleep(10_000);
        }
        return array_shift($this->requests);
    }

    #[\Override]
    public function respond(ResponseInterface $response): void
    {
        $this->responses[] = $response;
    }

    #[\Override]
    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    #[\Override]
    public function stop(): void
    {
        $this->running = false;
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->running;
    }

    /** @return list<ResponseInterface> */
    public function responses(): array
    {
        return $this->responses;
    }

    public function pendingRequests(): int
    {
        return count($this->requests);
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}

$pass = 0;
$fail = 0;
$check = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    if ($condition) {
        ++$pass;
        echo "PASS: {$message}\n";
        return;
    }
    ++$fail;
    echo "FAIL: {$message}\n";
};

$app = Bootstrap::createApp(false);
$worker = new RF06GracefulShutdownWorker([
    new ServerRequest('GET', new Uri('http://localhost/health/live')),
    new ServerRequest('GET', new Uri('http://localhost/health/ready')),
]);
$runtime = new RoadRunnerRuntime($app, $worker);
$exitCode = $runtime->run();
$responses = $worker->responses();
$errors = $worker->errors();

$check($exitCode === 0, 'SIGTERM drain exits with success (exit='.$exitCode.' errors='.implode('|', $errors).')');
$check(count($responses) === 1, 'current request completes before drain');
$check($responses[0]->getStatusCode() === 200, 'current request receives successful response (status='.$responses[0]->getStatusCode().')');
$check($worker->pendingRequests() === 1, 'request after shutdown signal is not consumed');
$check(!$runtime->isRunning(), 'runtime is stopped after graceful drain');
$check($worker->isRunning() === false, 'worker stop boundary is invoked');

$reflection = new ReflectionClass(RoadRunnerRuntime::class);
$signalsInstalled = $reflection->getProperty('signalsInstalled');
$ownedSignals = $reflection->getProperty('ownedSignals');
$check($signalsInstalled->getValue($runtime) === false, 'signal handlers are restored after graceful shutdown');
$check($ownedSignals->getValue($runtime) === [], 'runtime releases owned signal list');

$applicationRejectsAfterShutdown = false;
try {
    $app->handle(new ServerRequest('GET', new Uri('http://localhost/health/live')));
} catch (LogicException $exception) {
    $applicationRejectsAfterShutdown = $exception->getMessage() === 'Application has already been shut down.';
}
$check($applicationRejectsAfterShutdown, 'application rejects requests after shutdown');

printf("Graceful-shutdown characterization: %d pass, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
