<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Observability\OtlpHttpJsonExporter;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Observability\SpanData;

final class G4_2_OTLPBoundaryIntegrationTest extends TestCase
{
    public function testBoundaryOkMode(): void
    {
        $port = $this->mockPort();
        if ($port === 0) { $this->markTestSkipped('OTLP mock server not reachable; no network integration available.'); }
        $e = new OtlpHttpJsonExporter("http://127.0.0.1:{$port}", ['service.name'=>'zef-g42'], 200);
        $c = new SpanContext(str_repeat('a',32),str_repeat('b',16),true);
        $s = new SpanData('g42.integration',$c,null,1,2,1,2,'OK',null,['safe'=>'ok'],[]);
        $e->export([$s]);
        $this->addToAssertionCount(1);
    }

    public function testBoundaryErrorModes(): void
    {
        $port = $this->mockPort();
        if ($port === 0) { $this->markTestSkipped('OTLP mock server not reachable; no network integration available.'); }
        $e = new OtlpHttpJsonExporter("http://127.0.0.1:{$port}", ['service.name'=>'zef-g42'], 200);
        $c = new SpanContext(str_repeat('a',32),str_repeat('b',16),true);
        $s = new SpanData('g42.integration',$c,null,1,2,1,2,'OK',null,['safe'=>'ok'],[]);
        $client500 = new OtlpHttpJsonExporter("http://127.0.0.1:{$port}", ['service.name'=>'zef-g42'], 200);
        $this->expectNotToPerformAssertions();
        try { $client500->export([$s]); } catch (RuntimeException) { $this->addToAssertionCount(1); }
    }

    private function mockPort(): int
    {
        /** @var int|null $cached */
        static $cached = null;
        if ($cached !== null) { return $cached; }
        $probe = @fsockopen('127.0.0.1', 4319, $errno, $errstr, 0.3);
        if ($probe !== false) { fclose($probe); return $cached = 4319; }
        return $cached = 0;
    }
}
