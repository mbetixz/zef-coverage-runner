<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/vendor/autoload.php';

use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\SpanData;
use Zef\Framework\Observability\SpanExporterInterface;
use Zef\Framework\Observability\SpanContext;

final class FlakyExporter implements SpanExporterInterface
{
    public int $attempts = 0;

    #[\Override]
    public function export(array $spans): void
    {
        ++$this->attempts;
        if ($this->attempts < 3) {
            throw new RuntimeException('transient export failure');
        }
    }

    #[\Override]
    public function shutdown(): void {}
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

putenv('ZEF_OTEL_RETRY_ATTEMPTS=2');
putenv('ZEF_OTEL_RETRY_DELAY_MS=0');
putenv('ZEF_OTEL_RETRY_DELAY_CAP_MS=0');
$exporter = new FlakyExporter();
$processor = new BatchSpanProcessor($exporter, batchSize: 1);
$context = new SpanContext(str_repeat('a', 32), str_repeat('b', 16));
$processor->onEnd(new SpanData('span', $context, null, 1, 1, 1, 1, 'UNSET', null, [], []));
$processor->flush();
$check($exporter->attempts === 3, 'retry policy preserves two retries after the initial export attempt');
$processor->shutdown();

putenv('ZEF_OTEL_RETRY_ATTEMPTS');
putenv('ZEF_OTEL_RETRY_DELAY_MS');
putenv('ZEF_OTEL_RETRY_DELAY_CAP_MS');
printf("Retry/backoff characterization: %d pass, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
