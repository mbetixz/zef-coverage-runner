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
$runtime = new RoadRunnerRuntime(
    $app,
    new InMemoryWorker([new ServerRequest('GET', new Uri('http://localhost/health/live'))]),
    installSignalHandlers: false,
);

$reflection = new ReflectionClass($runtime);
/** @var array{resource_capacity:int,saturation_percent:int,control_plane_enabled:bool,distributed_compatibility:bool} $config */
$config = $reflection->getProperty('runtimeConfig')->getValue($runtime);
$check($config['resource_capacity'] === 1, 'default resource capacity remains 1');
$check($config['saturation_percent'] === 90, 'default saturation threshold remains 90 percent');
$check($config['control_plane_enabled'] === false, 'control plane remains disabled by default');
$check($config['distributed_compatibility'] === true, 'distributed compatibility remains enabled');
$check($runtime->run() === 0, 'runtime boundary still exits successfully');

printf("Runtime boundary type contract characterization: %d pass, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
