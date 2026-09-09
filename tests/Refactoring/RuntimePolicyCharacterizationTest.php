<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/vendor/autoload.php';

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Zef\App\Bootstrap;
use Zef\Framework\Runtime\InMemoryWorker;
use Zef\Framework\Runtime\RoadRunnerRuntime;

$pass = 0; $fail = 0;
$check = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    if ($condition) { ++$pass; echo "PASS: {$message}\n"; return; }
    ++$fail; echo "FAIL: {$message}\n";
};
$throws = static function (string $class, callable $operation): bool {
    try { $operation(); } catch (Throwable $exception) { return $exception instanceof $class; }
    return false;
};
$app = Bootstrap::createApp(false);
$worker = new InMemoryWorker([
    new ServerRequest('GET', new Uri('http://localhost/health/live')),
    new ServerRequest('GET', new Uri('http://localhost/health/ready')),
]);
$runtime = new RoadRunnerRuntime($app, $worker, installSignalHandlers: false);
$check($runtime->run() === 0, 'runtime serves requests and exits cleanly');
$check(count($worker->responses()) === 2, 'worker receives both responses');
$check($worker->responses()[0]->getStatusCode() === 200 && $worker->responses()[1]->getStatusCode() === 200, 'health responses remain successful');
$check(!$runtime->isRunning(), 'runtime is stopped after worker exhaustion');
$check($runtime->handledRequests() === 2, 'handled request count is preserved');
$reflection = new ReflectionClass($runtime);
$signals = $reflection->getProperty('signalsInstalled');
$check($signals->getValue($runtime) === false, 'shutdown restores signal ownership');
$started = $reflection->getProperty('started');
$check($started->getValue($runtime) === true, 'runtime is marked single-use after run');
$check($throws(LogicException::class, static fn() => $runtime->run()), 'runtime rejects restart after shutdown');

printf("Runtime policy characterization: %d pass, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
