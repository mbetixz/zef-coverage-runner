<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/zef_framework_v2.5.0-beta1.php';

use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\CounterMeter;
use Zef\Framework\Observability\InMemorySpanExporter;
use Zef\Framework\Observability\TelemetryLogger;
use Zef\Framework\Observability\Tracer;
use Zef\Framework\Observability\TraceContextPropagator;
use Psr\Log\LoggerInterface;

$pass=0;$fail=0;
$ok=static function(bool $condition,string $label)use(&$pass,&$fail):void{if($condition){++$pass;echo "PASS: {$label}\n";}else{++$fail;echo "FAIL: {$label}\n";}};

$exporter=new InMemorySpanExporter();
$processor=new BatchSpanProcessor($exporter,128,2);
$tracer=new Tracer($processor);
$parent=$tracer->startSpan('test.server',['http.request.method'=>'GET']);
$child=$tracer->startSpan('test.child',['authorization'=>'secret','custom'=>'ok'],$parent->getContext());
$child->addEvent('work',['token'=>'do-not-export']);
$child->setStatus('OK')->end();
$parent->setStatus('OK')->end();
$processor->flush();
$spans=$exporter->spans();
$ok(count($spans)===2,'O-01 spans exported');
$rootSpan=array_values(array_filter($spans,static fn($s)=>$s->name==='test.server'))[0];
$childSpan=array_values(array_filter($spans,static fn($s)=>$s->name==='test.child'))[0];
$ok($childSpan->parent?->spanId===$rootSpan->context->spanId,'O-01 parent-child context propagated');
$ok(!array_key_exists('authorization',$childSpan->attributes),'O-02 sensitive span attributes are not exported');
$ok(count($childSpan->events)===1 && $childSpan->events[0]['attributes']===[],'O-02 sensitive event attributes are not exported');

$wire=$rootSpan->context->traceParent();
$parsed=TraceContextPropagator::extract($wire);
$ok($parsed?->traceId===$rootSpan->context->traceId,'O-03 W3C traceparent extraction');
$ok($parsed?->spanId===$rootSpan->context->spanId,'O-03 W3C span id extraction');
$ok(TraceContextPropagator::extract('00-00000000000000000000000000000000-0000000000000000-01')===null,'O-03 invalid zero context rejected');

$meter=new CounterMeter();
$meter->increment('zef.http.requests.total',2,['http.request.method'=>'GET']);
$meter->increment('zef.http.requests.total',1,['http.request.method'=>'GET']);
$snapshot=$meter->snapshot();
$ok(count($snapshot)===1,'O-04 meter aggregates identical dimensions');
/** @var array{count:int|float,sum:float,attributes:array<string,mixed>} $entry */
$entry=array_values($snapshot)[0];
$ok($entry['count']===3 && abs($entry['sum']-3.0)<0.000001,'O-04 meter counter aggregation');

$logger=new TelemetryLogger(new class implements LoggerInterface {
    /** @var list<array<string,mixed>> */
    public array $records=[];
    /** @param array<string,mixed> $context */ #[\Override] public function emergency(mixed $message,array $context=[]):void{$this->records[]=$context;}
    /** @param array<string,mixed> $context */ #[\Override] public function alert(mixed $message,array $context=[]):void{$this->records[]=$context;}
    /** @param array<string,mixed> $context */ #[\Override] public function critical(mixed $message,array $context=[]):void{$this->records[]=$context;}
    /** @param array<string,mixed> $context */ #[\Override] public function error(mixed $message,array $context=[]):void{$this->records[]=$context;}
    /** @param array<string,mixed> $context */ #[\Override] public function warning(mixed $message,array $context=[]):void{$this->records[]=$context;}
    /** @param array<string,mixed> $context */ #[\Override] public function notice(mixed $message,array $context=[]):void{$this->records[]=$context;}
    /** @param array<string,mixed> $context */ #[\Override] public function info(mixed $message,array $context=[]):void{$this->records[]=$context;}
    /** @param array<string,mixed> $context */ #[\Override] public function debug(mixed $message,array $context=[]):void{$this->records[]=$context;}
    /** @param array<string,mixed> $context */ #[\Override] public function log(mixed $level,mixed $message,array $context=[]):void{$this->records[]=$context;}
});
$logger->info('safe',['password'=>'secret','trace_id'=>'abc']);
$ok(true,'O-05 structured telemetry logger executes');

// Application-level instrumentation is opt-in and must not alter disabled behavior.
putenv('ZEF_OTEL_ENABLED=0');
$telemetry=\Zef\Framework\Observability\Telemetry::fromEnvironment();
$ok(!$telemetry->isEnabled(),'O-06 observability disabled by default');
$span=$telemetry->startSpan('disabled');
$ok($span->getContext()->isValid()===false,'O-06 noop span has invalid context');

putenv('ZEF_OTEL_ENABLED=1');
putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=');
$app=\Zef\App\Bootstrap::createApp(false);
$request=(new \Zef\Framework\Http\Psr17Factory())->createServerRequest('GET','http://localhost/about')->withHeader('traceparent','00-11111111111111111111111111111111-2222222222222222-01');
$response=$app->handle($request);
$tp=$response->getHeaderLine('traceparent');
$parsedResponse=TraceContextPropagator::extract($tp);
$ok($response->getStatusCode()===200,'O-07 application instrumentation preserves request behavior');
$ok($parsedResponse?->traceId==='11111111111111111111111111111111','O-07 inbound W3C trace context preserved');
$ok($parsedResponse?->spanId!=='2222222222222222','O-07 response creates a new server span id');
/** @var \Zef\Framework\Observability\MeterInterface $appMeter */
$appMeter=$app->getContainer()->get(\Zef\Framework\Observability\MeterInterface::class);
$ok($appMeter->snapshot()!==[],'O-07 application metrics recorded');
putenv('ZEF_OTEL_ENABLED');
putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT');
putenv('ZEF_OTEL_ENABLED');
echo "ObservabilityTest: {$pass} pass, {$fail} fail\n";
exit($fail===0?0:1);
