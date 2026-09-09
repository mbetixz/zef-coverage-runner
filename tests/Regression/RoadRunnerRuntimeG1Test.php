<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\App\Bootstrap;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Runtime\InMemoryWorker;
use Zef\Framework\Runtime\RoadRunnerRuntime;
use Zef\Framework\Runtime\RoadRunnerWorkerAdapter;

$fail = 0; $pass = 0;
$ok = static function(bool $condition, string $label) use (&$pass, &$fail): void {
    if ($condition) { ++$pass; echo "PASS: {$label}\n"; }
    else { ++$fail; echo "FAIL: {$label}\n"; }
};

final class G1RuntimeScopeHandler implements RequestHandlerInterface
{
    private string $nonce;
    public function __construct() { $this->nonce = bin2hex(random_bytes(16)); }
    #[\Override]
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type'=>'text/plain'], $this->nonce);
    }
}


final class G1RoadRunnerLikeWorker
{
    public bool $stopped = false;
    /** @var list<ResponseInterface> */
    public array $responses = [];
    /** @var list<\Psr\Http\Message\ServerRequestInterface> */
    private array $requests;
    /** @param list<\Psr\Http\Message\ServerRequestInterface> $requests */
    public function __construct(array $requests) { $this->requests = $requests; }
    public function waitRequest(): ?\Psr\Http\Message\ServerRequestInterface { return array_shift($this->requests) ?? null; }
    public function respond(ResponseInterface $response): void { $this->responses[] = $response; }
    public function error(string $message): void {}
    public function stop(): void { $this->stopped = true; }
    public function isStopped(): bool { return $this->stopped; }
}

final class G1RuntimeProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getModuleName(): string { return 'g1-runtime'; }
    /** @return array<string,mixed> */
    #[\Override]
    public function getConfig(): array
    {
        return [
            'services' => [
                'g1.runtime.scope-handler' => [
                    'factory' => static fn(): G1RuntimeScopeHandler => new G1RuntimeScopeHandler(),
                    'deps' => [],
                    'lifetime' => ServiceLifetime::REQUEST,
                ],
            ],
            'routes' => [
                ['method'=>'GET','path'=>'/__g1_scope','handler'=>'g1.runtime.scope-handler'],
            ],
        ];
    }
}

$app = Bootstrap::createApp(false);
$app->addProvider(new G1RuntimeProvider());
$requests = [
    new ServerRequest('GET', new Uri('http://localhost/__g1_scope',['localhost'])),
    new ServerRequest('GET', new Uri('http://localhost/__g1_scope',['localhost'])),
];
$worker = new InMemoryWorker($requests);
$runtime = new RoadRunnerRuntime($app, $worker, installSignalHandlers:false);
$rc = $runtime->run();
$responses = $worker->responses();
$body = static fn(ResponseInterface $response): string => $response->getBody()->getContents();

$ok($rc === 0, 'G1 runtime exits cleanly');
$ok(count($responses) === 2, 'G1 worker processes two requests');
$ok($responses[0]->getStatusCode() === 200 && $responses[1]->getStatusCode() === 200, 'G1 responses are successful');
$ok($body($responses[0]) !== $body($responses[1]), 'G1 request-scoped service is isolated per request');
$ok($app->isBooted(), 'G1 application remains booted across requests');
$ok(!$runtime->isRunning(), 'G1 runtime stops after worker exhaustion');
$ok($runtime->handledRequests() === 2, 'G1 handled request counter is accurate');

$worker2 = new InMemoryWorker([new ServerRequest('GET', new Uri('http://localhost/__g1_scope',['localhost']))]);
$app2 = Bootstrap::createApp(false);
$app2->addProvider(new G1RuntimeProvider());
$runtime2 = new RoadRunnerRuntime($app2, $worker2, maxJobs:1, installSignalHandlers:false);
$ok($runtime2->run() === 0, 'G1 maxJobs worker completes successfully');
$ok($runtime2->handledRequests() === 1 && count($worker2->responses()) === 1, 'G1 maxJobs stops after configured request count');


$rrLike = new G1RoadRunnerLikeWorker([new ServerRequest('GET', new Uri('http://localhost/about',['localhost']))]);
$adapter = new RoadRunnerWorkerAdapter($rrLike);
$rrRequest = $adapter->waitRequest();
$ok($rrRequest instanceof \Psr\Http\Message\ServerRequestInterface, 'G1 RoadRunner adapter receives PSR-7 request');
$adapter->respond(new Response(204, [], ''));
$adapter->stop();
$ok(count($rrLike->responses) === 1 && $rrLike->stopped, 'G1 RoadRunner adapter delegates respond/stop');
$ok($adapter->isRunning() === false, 'G1 RoadRunner adapter observes stopped worker');

$ref = new ReflectionClass(RoadRunnerRuntime::class);
$ok($ref->isFinal(), 'G1 runtime implementation remains final');

printf("\nTOTAL PASS: %d  FAIL: %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
