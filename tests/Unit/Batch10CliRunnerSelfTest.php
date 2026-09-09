<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Test\CliRunner;

/**
 * Batch 10 coverage: formalize the framework's own CLI self-test suite
 * (Zef\Test\CliRunner, previously 0% - the largest uncovered surface) as a
 * PHPUnit test. Running it exercises the PSR contracts, application routes,
 * container lifetimes, security/URI hardening, router semantics and pipeline
 * error-boundary checks through the real framework stack.
 *
 * Determinism: telemetry is disabled (ZEF_OTEL_ENABLED=0 restored in
 * tearDown) so no export/network path is reached; output is captured with
 * ob_start(); no sleeps, no wall-clock assertions.
 */
final class Batch10CliRunnerSelfTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $previousEnv = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ZEF_OTEL_ENABLED', 'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT', 'ZEF_OTEL_SHUTDOWN_DRAIN_MS'] as $name) {
            $this->previousEnv[$name] = getenv($name);
        }
        putenv('ZEF_OTEL_ENABLED=0');
        putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=');
        putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=0');
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
        parent::tearDown();
    }

    public function testTextModeSelfTestSuiteExitsZero(): void
    {
        $runner = new CliRunner();
        ob_start();
        try {
            $exit = $runner->run(false);
        } finally {
            $output = (string) ob_get_clean();
        }

        self::assertSame(0, $exit);
        self::assertStringContainsString('ZEF Framework v', $output);
        self::assertMatchesRegularExpression('/PASSED: \d+  FAILED: 0/', $output);
    }

    public function testHtmlModeSelfTestRendersDocument(): void
    {
        $runner = new CliRunner();
        ob_start();
        try {
            $exit = $runner->run(true);
        } finally {
            $output = (string) ob_get_clean();
        }

        self::assertSame(0, $exit);
        self::assertStringContainsString('<!doctype html>', $output);
        self::assertStringContainsString('</pre></body></html>', $output);
    }
}
