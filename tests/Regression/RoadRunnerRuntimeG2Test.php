<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/vendor/autoload.php';

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Zef\App\Bootstrap;
use Zef\Framework\Http\Response;
use Zef\Framework\Runtime\InMemoryWorker;
use Zef\Framework\Runtime\RoadRunnerRuntime;
use Zef\Framework\Runtime\WorkerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$fail = 0; $pass = 0;
$ok = static function (bool $condition, string $label) use (&$fail, &$pass): void {
    if ($condition) { ++$pass; echo "PASS: {$label}\n"; }
    else { ++$fail; echo "FAIL: {$label}\n"; }
};

final class G2ThrowingWorker implements WorkerInterface
{
    private bool $running = true;
    #[\Override]
    public function waitRequest(): ?ServerRequestInterface { throw new RuntimeException('transport read failure'); }
    #[\Override]
    public function respond(ResponseInterface $response): void {}
    #[\Override]
    public function error(string $message): void { $this->lastError = $message; }
    #[\Override]
    public function stop(): void { $this->running = false; }
    #[\Override]
    public function isRunning(): bool { return $this->running; }
    public string $lastError = '';
}

$app = Bootstrap::createApp(false);
$worker = new InMemoryWorker([]);
$runtime = new RoadRunnerRuntime($app, $worker, installSignalHandlers: false);
$ok($runtime->run() === 0, 'G2 empty worker exits cleanly');
$ok(!$runtime->isRunning(), 'G2 runtime is stopped after normal exhaustion');
$thrown = false;
try { $runtime->run(); } catch (LogicException) { $thrown = true; }
$ok($thrown, 'G2 runtime instances are single-use after shutdown');

$app2 = Bootstrap::createApp(false);
$throwingWorker = new G2ThrowingWorker();
$runtime2 = new RoadRunnerRuntime($app2, $throwingWorker, installSignalHandlers: false);
$ok($runtime2->run() === 1, 'G2 transport read failure produces controlled runtime exit');
$ok(!$runtime2->isRunning() && !$throwingWorker->isRunning(), 'G2 transport failure stops worker and runtime');
$ok(str_contains($throwingWorker->lastError, 'transport read failure'), 'G2 transport failure is reported to worker error channel');

$app3 = Bootstrap::createApp(false);
$app3->boot();
$app3->shutdown();
$app3->shutdown();
$ok(true, 'G2 application shutdown is idempotent');
$throws = false;
try { $app3->handle(new ServerRequest('GET', new Uri('http://localhost/about'))); } catch (LogicException) { $throws = true; }
$ok($throws, 'G2 application rejects requests after shutdown');

$memoryWorker = new InMemoryWorker([new ServerRequest('GET', new Uri('http://localhost/about'))]);
$app4 = Bootstrap::createApp(false);
$memoryRuntime = new RoadRunnerRuntime($app4, $memoryWorker, memoryLimitBytes: 1, installSignalHandlers: false);
$ok($memoryRuntime->run() === 2, 'G2 pre-request memory guard returns controlled exit code');
$ok($memoryRuntime->handledRequests() === 0, 'G2 pre-request memory guard prevents request dispatch');

echo "\nTOTAL PASS: {$pass}  FAIL: {$fail}\n";
exit($fail === 0 ? 0 : 1);
