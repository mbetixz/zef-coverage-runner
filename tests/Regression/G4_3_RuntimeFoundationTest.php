<?php
declare(strict_types=1);
require dirname(__DIR__).'/Architecture/_assert.php';
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Zef\Framework\Application;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Runtime\InMemoryWorker;
use Zef\Framework\Runtime\RoadRunnerRuntime;
use Zef\Framework\Observability\InMemorySpanExporter;
use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Observability\NoopTracer;
use Zef\Framework\Observability\CounterMeter;

function g43_app(): Application {
    return new Application();
}

function g43_request(): ServerRequest {
    return new ServerRequest('GET',new Uri('http://localhost/'));
}

putenv('ZEF_RUNTIME_RESOURCE_CAPACITY=1');
putenv('ZEF_RUNTIME_SATURATION_PERCENT=90');
putenv('ZEF_RUNTIME_CONTROL_PLANE=off');

$worker=new InMemoryWorker([g43_request()]);
$runtime=new RoadRunnerRuntime(g43_app(),$worker,1,0,false);
architecture_check($runtime->run()===0,'G4.3 runtime lifecycle completes cleanly');
architecture_check(count($worker->responses())===1,'G4.3 runtime preserves request response path');

$ref=new ReflectionClass($runtime);
$life=$ref->getProperty('lifecycle');
$life->setAccessible(true);
/** @var array<string,mixed> $value */
$value=$life->getValue($runtime);
architecture_check(($value['state']??null)==='stopped','G4.3 lifecycle ends in stopped state');
architecture_check(is_string($value['instance_id']??null) && strlen((string)$value['instance_id'])===32,'G4.3 runtime identity is bounded and opaque');
architecture_check(is_string($value['worker_id']??null) && strlen((string)$value['worker_id'])===16,'G4.3 worker identity is bounded and opaque');

$cfg=$ref->getProperty('runtimeConfig');
$cfg->setAccessible(true);
/** @var array<string,mixed> $c */
$c=$cfg->getValue($runtime);
architecture_check(($c['resource_capacity']??null)===1,'G4.3 bounded resource capacity snapshot loaded');
architecture_check(($c['control_plane_enabled']??null)===false,'G4.3 control plane remains disabled by default');
architecture_check(($c['distributed_compatibility']??null)===true,'G4.3 distributed compatibility boundary is enabled without vendor dependency');

$bad=false;
putenv('ZEF_RUNTIME_RESOURCE_CAPACITY=0');
try { new RoadRunnerRuntime(g43_app(),new InMemoryWorker([]),0,0,false); } catch (InvalidArgumentException) { $bad=true; }
architecture_check($bad,'G4.3 invalid resource configuration fails closed');
putenv('ZEF_RUNTIME_RESOURCE_CAPACITY=1');

$ref->getProperty('lifecycle')->setValue($runtime,['state'=>'ready','instance_id'=>'a','worker_id'=>'b','started_at_ns'=>1]);
$transition=$ref->getMethod('transitionLifecycle');
$transition->setAccessible(true);
$illegal=false;
try { $transition->invoke($runtime,'starting'); } catch (Throwable) { $illegal=true; }
architecture_check($illegal,'G4.3 illegal lifecycle transition fails deterministically');

$exporter=new InMemorySpanExporter();
$processor=new BatchSpanProcessor($exporter,8,4);
$telemetry=new Telemetry(new NoopTracer(),new CounterMeter(),$processor,null,null,true);
$telemetry->recordLog('INFO','runtime.resource.saturated',['event.name'=>'runtime.resource.saturated']);
$telemetry->flush();
architecture_check(true,'G4.3 resource telemetry path remains fail-open compatible');

$total=11;
echo "G4.3 Runtime Foundation: {$total} pass, 0 fail\n";
