<?php
declare(strict_types=1);
$root=dirname(__DIR__,2); require $root.'/vendor/autoload.php'; require $root.'/vendor/autoload.php';
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Zef\App\Bootstrap;
use Zef\Framework\Runtime\InMemoryWorker;
use Zef\Framework\Runtime\RoadRunnerRuntime;
$fail=0;$pass=0;
$ok=static function(bool $c,string $m)use(&$fail,&$pass):void{if($c){++$pass;echo "PASS: {$m}\n";}else{++$fail;echo "FAIL: {$m}\n";}};
$app=Bootstrap::createApp(false);
$w=new InMemoryWorker([
 new ServerRequest('GET',new Uri('http://localhost/health/live')),
 new ServerRequest('GET',new Uri('http://localhost/health/ready')),
]);
$r=new RoadRunnerRuntime($app,$w,installSignalHandlers:false);
$ok($r->run()===0,'G3 runtime serves health/readiness requests');
$responses=$w->responses();
$ok(count($responses)===2,'G3 both health endpoints responded');
$ok($responses[0]->getStatusCode()===200 && $responses[1]->getStatusCode()===200,'G3 health/readiness return 200 while worker is ready');
$ok($responses[0]->getHeaderLine('Cache-Control')==='no-store' && $responses[1]->getHeaderLine('Cache-Control')==='no-store','G3 health responses are non-cacheable');
$ok(!$r->isRunning(),'G3 runtime stops cleanly after request exhaustion');
$signalsInstalled = (new ReflectionProperty(RoadRunnerRuntime::class, 'signalsInstalled'))->getValue($r);
$ownedSignals = (new ReflectionProperty(RoadRunnerRuntime::class, 'ownedSignals'))->getValue($r);
$ok($signalsInstalled === false && $ownedSignals === [], 'G3 signal handlers are restored after runtime shutdown');
// Runtime single-use and shutdown remain invariant after G3 additions.
$ok($app->isBooted(),'G3 application remains booted through health lifecycle');
$throws=false;try{$app->handle(new ServerRequest('GET',new Uri('http://localhost/health/live')));}catch(LogicException){$throws=true;}
$ok($throws,'G3 application cannot accept requests after runtime shutdown');
echo "TOTAL PASS: {$pass}  FAIL: {$fail}\n"; exit($fail===0?0:1);
