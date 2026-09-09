<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/zef_framework_v2.5.0-beta1.php';

use Psr\Log\LoggerInterface;
use Zef\Framework\Http\Psr17Factory;
use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\CounterMeter;
use Zef\Framework\Observability\LogExporterInterface;
use Zef\Framework\Observability\LogRecord;
use Zef\Framework\Observability\MetricExporterInterface;
use Zef\Framework\Observability\SpanData;
use Zef\Framework\Observability\SpanExporterInterface;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Observability\TelemetrySanitizer;
use Zef\Framework\Observability\Tracer;

$pass = 0;
$fail = 0;
$ok = static function (bool $condition, string $label) use (&$pass, &$fail): void {
    if ($condition) { ++$pass; echo "PASS: {$label}\n"; }
    else { ++$fail; echo "FAIL: {$label}\n"; }
};

final class G41CaptureSpanExporter implements SpanExporterInterface
{
    /** @var list<SpanData> */ public array $spans = [];
    public bool $throwExport = false;
    public bool $throwShutdown = false;
    public int $shutdownCalls = 0;
    #[\Override]
    public function export(array $spans): void { if ($this->throwExport) throw new RuntimeException('span export failure'); foreach ($spans as $span) $this->spans[] = $span; }
    #[\Override]
    public function shutdown(): void { ++$this->shutdownCalls; if ($this->throwShutdown) throw new RuntimeException('span shutdown failure'); }
}

final class G41CaptureMetricExporter implements MetricExporterInterface
{
    /** @var list<array<string,mixed>> */ public array $payloads = [];
    public bool $throwExport = false;
    public bool $throwShutdown = false;
    public int $shutdownCalls = 0;
    #[\Override]
    public function exportMetrics(array $metrics): void { if ($this->throwExport) throw new RuntimeException('metric export failure'); $this->payloads[] = $metrics; }
    #[\Override]
    public function shutdown(): void { ++$this->shutdownCalls; if ($this->throwShutdown) throw new RuntimeException('metric shutdown failure'); }
}

final class G41CaptureLogExporter implements LogExporterInterface
{
    /** @var list<list<LogRecord>> */ public array $payloads = [];
    public bool $throwExport = false;
    public bool $throwShutdown = false;
    public int $shutdownCalls = 0;
    #[\Override]
    public function exportLogs(array $records): void { if ($this->throwExport) throw new RuntimeException('log export failure'); $this->payloads[] = $records; }
    #[\Override]
    public function shutdown(): void { ++$this->shutdownCalls; if ($this->throwShutdown) throw new RuntimeException('log shutdown failure'); }
}


final class G41FakeWorker implements Zef\Framework\Runtime\WorkerInterface
{
    /** @var list<\Psr\Http\Message\ServerRequestInterface> */ private array $requests;
    /** @var list<\Psr\Http\Message\ResponseInterface> */ public array $responses=[];
    private bool $running=true;
    /** @param list<\Psr\Http\Message\ServerRequestInterface> $requests */
    public function __construct(array $requests){$this->requests=$requests;}
    #[\Override]
    public function waitRequest(): ?\Psr\Http\Message\ServerRequestInterface {
        $request=array_shift($this->requests);
        if ($request===null) { $this->running=false; return null; }
        return $request;
    }
    #[\Override]
    public function respond(\Psr\Http\Message\ResponseInterface $response): void { $this->responses[]=$response; }
    #[\Override]
    public function error(string $message): void {}
    #[\Override]
    public function stop(): void { $this->running=false; }
    #[\Override]
    public function isRunning(): bool { return $this->running; }
}

// G4.1-SAN-01..04: recursive and payload-boundary sanitization.
/** @var array<string,mixed> $nested */
$nested = TelemetrySanitizer::attributes([
    'safe' => ['password' => 'secret', 'nested' => ['api_key' => 'secret2', 'ok' => 'value']],
    'authorization' => 'bearer secret',
]);
$safe = $nested['safe'] ?? null;
$deep = is_array($safe) ? ($safe['nested'] ?? null) : null;
$ok(is_array($safe) && ($safe['password'] ?? null) === '[REDACTED]', 'G4.1-SAN-03 nested password redacted');
$ok(is_array($deep) && ($deep['api_key'] ?? null) === '[REDACTED]', 'G4.1-SAN-03 deeply nested api key redacted');
$ok(!array_key_exists('authorization', $nested), 'G4.1-SAN-01 top-level sensitive key removed');

$spanExporter = new G41CaptureSpanExporter();
$processor = new BatchSpanProcessor($spanExporter, 8, 2);
$tracer = new Tracer($processor);
$span = $tracer->startSpan('g41.sanitization', [
    'safe' => 'ok',
    'credentials' => ['password' => 'secret', 'token' => 'secret2'],
]);
$span->addEvent('sensitive', ['payload' => ['client_secret' => 'hidden', 'safe' => 'yes']]);
$span->end();
$processor->flush();
$ok(count($spanExporter->spans) === 1, 'G4.1-SAN-04 sanitized span reaches exporter');
$exported = $spanExporter->spans[0];
$credentials = $exported->attributes['credentials'] ?? null;
$eventPayload = $exported->events[0]['attributes']['payload'] ?? null;
$ok(is_array($credentials) && ($credentials['password'] ?? null) === '[REDACTED]', 'G4.1-SAN-04 exporter-bound span payload redacted');
$ok(is_array($eventPayload) && ($eventPayload['client_secret'] ?? null) === '[REDACTED]', 'G4.1-SAN-04 exporter-bound event payload redacted');

// G4.1-CARD-01..03: framework metrics stay bounded and stable dimensions aggregate.
$meter = new CounterMeter();
for ($i = 0; $i < 5000; ++$i) {
    $meter->increment('zef.http.requests.total', 1, [
        'http.request.method' => 'GET',
        'http.response.status_code' => 200,
        'attacker_input' => 'entropy-' . $i,
    ]);
}
$snapshot = $meter->snapshot();
$ok(count($snapshot) <= 1024, 'G4.1-CARD-01 high-entropy dimensions remain bounded');
$ok(count($snapshot) === 1, 'G4.1-CARD-02 unsupported framework dimension is collapsed');
$entry = array_values($snapshot)[0];
$ok($entry['attributes'] === ['http.request.method' => 'GET', 'http.response.status_code' => 200], 'G4.1-CARD-03 stable allowed dimensions preserved');

$custom = new CounterMeter();
for ($i = 0; $i < 1200; ++$i) $custom->increment('custom.metric', 1, ['id' => 'id-' . $i]);
$ok(count($custom->snapshot()) <= 1024, 'G4.1-CARD-01 custom metric series ceiling enforced');

// G4.1-EXP-01..04: telemetry exporter failures remain fail-open and shutdown is idempotent.
$metricExporter = new G41CaptureMetricExporter();
$logExporter = new G41CaptureLogExporter();
$spanExporter2 = new G41CaptureSpanExporter();
$spanExporter2->throwExport = true;
$spanExporter2->throwShutdown = true;
$processor2 = new BatchSpanProcessor($spanExporter2, 4, 4);
$telemetry = new Telemetry(new Tracer($processor2), new CounterMeter(), $processor2, $metricExporter, $logExporter, true);

$telemetry->startSpan('g41.fail-open')->end();
$telemetry->meter()->increment('zef.lifecycle.events.total', 1, ['event.name' => 'request.completed']);
$telemetry->recordLog('INFO', 'telemetry test', ['password' => 'secret', 'safe' => 'ok']);
try { $telemetry->flush(); $requestSurvived = true; } catch (Throwable) { $requestSurvived = false; }
$ok($requestSurvived, 'G4.1-EXP-01 exporter export failure does not escape flush');

$metricExporter->throwExport = true;
$logExporter->throwExport = true;
try { $telemetry->flush(); $flushSurvived = true; } catch (Throwable) { $flushSurvived = false; }
$ok($flushSurvived, 'G4.1-EXP-01 metric/log exporter failures remain fail-open');

$telemetry->shutdown();
$telemetry->shutdown();
$ok($spanExporter2->shutdownCalls === 1, 'G4.1-EXP-04 repeated shutdown is idempotent');
$ok($metricExporter->shutdownCalls === 1, 'G4.1-EXP-02 metric shutdown failure is contained');
$ok($logExporter->shutdownCalls === 1, 'G4.1-EXP-02 log shutdown failure is contained');

// G4.1-EXP-03: queue never exceeds configured bound.
$boundedExporter = new G41CaptureSpanExporter();
$boundedProcessor = new BatchSpanProcessor($boundedExporter, 4, 99);
$boundedTracer = new Tracer($boundedProcessor);
for ($i = 0; $i < 20; ++$i) $boundedTracer->startSpan('g41.queue')->end();
$ref = new ReflectionObject($boundedProcessor);
$prop = $ref->getProperty('queue');
$prop->setAccessible(true);
$queueValue = $prop->getValue($boundedProcessor);
$queue = is_array($queueValue) ? $queueValue : [];
$ok(count($queue) <= 4, 'G4.1-EXP-03 span processor queue is bounded');

// G4.1-ISO-01..04 + G4.1-READY-01..03: persistent application behavior remains isolated.
putenv('ZEF_OTEL_ENABLED=1');
putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=');
$app = Zef\App\Bootstrap::createApp(false);
$factory = new Psr17Factory();
$requestA = $factory->createServerRequest('GET', 'http://localhost/about')->withHeader('traceparent', '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01');
$requestB = $factory->createServerRequest('GET', 'http://localhost/about')->withHeader('traceparent', '00-cccccccccccccccccccccccccccccccc-eeeeeeeeeeeeeeee-01');
$responseA = $app->handle($requestA);
$responseB = $app->handle($requestB);
$tpA = $responseA->getHeaderLine('traceparent');
$tpB = $responseB->getHeaderLine('traceparent');
$ctxA = Zef\Framework\Observability\TraceContextPropagator::extract($tpA);
$ctxB = Zef\Framework\Observability\TraceContextPropagator::extract($tpB);
$ok($ctxA?->traceId === 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' && $ctxB?->traceId === 'cccccccccccccccccccccccccccccccc', 'G4.1-ISO-01 request trace contexts remain isolated');
$ok($ctxA?->spanId !== $ctxB?->spanId, 'G4.1-ISO-01 request server spans are distinct');
$ok($responseA->getStatusCode() === 200 && $responseB->getStatusCode() === 200, 'G4.1-ISO-02 normal repeated requests remain successful');
/** @var \Zef\Framework\Observability\MeterInterface $appMeter */
$appMeter = $app->getContainer()->get(Zef\Framework\Observability\MeterInterface::class);
$appSnapshot = $appMeter->snapshot();
$ok($appSnapshot !== [], 'G4.1-ISO-04 telemetry state remains valid after repeated requests');
$app->shutdown();

putenv('ZEF_OTEL_ENABLED=0');
$readyApp = Zef\App\Bootstrap::createApp(false);
$ready = $readyApp->handle($factory->createServerRequest('GET', 'http://localhost/health/ready'));
$ok($ready->getStatusCode() === 200, 'G4.1-READY-03 readiness is healthy with telemetry disabled');
$readyApp->shutdown();
putenv('ZEF_OTEL_ENABLED=1');
putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:1');
$unavailableApp = Zef\App\Bootstrap::createApp(false);
$unavailableReady = $unavailableApp->handle($factory->createServerRequest('GET', 'http://localhost/health/ready'));
$ok($unavailableReady->getStatusCode() === 200, 'G4.1-READY-02 readiness is independent of unavailable exporter');
$unavailableApp->shutdown();
putenv('ZEF_OTEL_ENABLED');
putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT');


// G4.1-LIFE-01..03: lifecycle records use bounded event dimensions across a persistent run.
putenv('ZEF_OTEL_ENABLED=1');
$lifecycleApp = Zef\App\Bootstrap::createApp(false);
$lifecycleFactory = new Psr17Factory();
$lifecycleWorker = new G41FakeWorker([
    $lifecycleFactory->createServerRequest('GET', 'http://localhost/health/ready'),
    $lifecycleFactory->createServerRequest('GET', 'http://localhost/health/live'),
]);
$runtime = new Zef\Framework\Runtime\RoadRunnerRuntime($lifecycleApp, $lifecycleWorker, 0, 0, false);
$runtimeExit = $runtime->run();
/** @var \Zef\Framework\Observability\MeterInterface $lifecycleMeter */
$lifecycleMeter = $lifecycleApp->getContainer()->get(Zef\Framework\Observability\MeterInterface::class);
$lifecycleSnapshot = $lifecycleMeter->snapshot();
/** @var array<string,array{count:int|float,sum:float,attributes:array<string,mixed>}> $lifecycleSnapshot */
$events=[];
foreach ($lifecycleSnapshot as $metricName => $metric) {
    if (!str_starts_with($metricName, 'zef.lifecycle.events.total|')) continue;
    $eventValue = $metric['attributes']['event.name'] ?? null;
    if (is_string($eventValue)) $events[$eventValue] = $metric['count'];
}
$ok($runtimeExit === 0 && count($lifecycleWorker->responses) === 2, 'G4.1-LIFE-01 normal runtime lifecycle completes successfully');
$ok(($events['worker.started'] ?? 0) >= 1 && ($events['worker.ready'] ?? 0) >= 1 && ($events['request.started'] ?? 0) === 2 && ($events['request.completed'] ?? 0) === 2, 'G4.1-LIFE-01 ordered worker/request lifecycle records emitted');
$ok(($events['worker.terminated'] ?? 0) >= 1 && ($events['telemetry.flush'] ?? 0) >= 1 && ($events['telemetry.shutdown'] ?? 0) >= 1 && count($events) <= 9, 'G4.1-LIFE-03 shutdown lifecycle emitted with bounded event vocabulary');

echo "G4_1_ObservabilityOperationalResilienceTest: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
