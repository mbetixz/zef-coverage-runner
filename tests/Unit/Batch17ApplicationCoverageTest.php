<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Zef\Framework\Application;
    use Zef\Framework\Config\ConfigProviderInterface;

    /**
     * Batch 17 coverage: the last two uncovered statements in
     * src/Framework/Application.php:
     *   - L129 boot(): clamp of a negative framework.container.max_cross_module_refs
     *   - L220 shutdown(): swallow of a Throwable thrown by the telemetry
     *     shutdown path (triggered via an invalid ZEF_OTEL_SHUTDOWN_DRAIN_MS
     *     environment value, without touching production code).
     *
     * Determinism: all env vars and superglobals are saved/restored in
     * setUp/tearDown; telemetry uses the in-memory span exporter (endpoint is
     * empty) so no network activity can occur; no wall-clock assertions.
     *
     * L220 mechanics: Application registers Telemetry as a lazy singleton
     * factory (Telemetry::fromEnvironment) that first runs during boot()'s
     * warmSingletons(). With ZEF_OTEL_ENABLED=1 and a valid drain value the
     * factory produces an enabled in-memory Telemetry. Telemetry::shutdown()
     * re-reads ZEF_OTEL_SHUTDOWN_DRAIN_MS at call time via envIntRequired();
     * setting it to a non-integer *after boot* makes shutdown() throw an
     * InvalidArgumentException that Application::shutdown() must swallow at
     * L220. The value is restored in tearDown() before the process exits, so
     * the register_shutdown_function() hook Telemetry added at factory time
     * finds a valid (or defaulted) value and is a silent no-op.
     *
     * Supply chain: no new dependencies, no changes to production code, and
     * tests/Regression/ is untouched (this file lives in tests/Unit).
     */
    final class Batch17ApplicationCoverageTest extends TestCase
    {
        /** @var array<string, string|false> */
        private array $previousEnv = [];

        /** @var array<mixed> */
        private array $previousServer = [];

        /** @var array<mixed> */
        private array $previousPost = [];

        /** @var array<mixed> */
        private array $previousGet = [];

        /** @var array<mixed> */
        private array $previousFiles = [];

        /** @var array<mixed> */
        private array $previousCookie = [];

        #[Override]
        protected function setUp(): void
        {
            parent::setUp();
            foreach (['ZEF_OTEL_ENABLED', 'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT', 'ZEF_OTEL_SHUTDOWN_DRAIN_MS'] as $name) {
                $this->previousEnv[$name] = getenv($name);
            }
            putenv('ZEF_OTEL_ENABLED=0');
            putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=');
            putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=0');

            $this->previousServer = $_SERVER;
            $this->previousPost = $_POST;
            $this->previousGet = $_GET;
            $this->previousFiles = $_FILES;
            $this->previousCookie = $_COOKIE;
        }

        #[Override]
        protected function tearDown(): void
        {
            $_SERVER = $this->previousServer;
            $_POST = $this->previousPost;
            $_GET = $this->previousGet;
            $_FILES = $this->previousFiles;
            $_COOKIE = $this->previousCookie;
            foreach ($this->previousEnv as $name => $value) {
                $restored = $value === false ? null : $value;
                if ($restored === null) {
                    putenv($name);
                } else {
                    putenv($name . '=' . $restored);
                }
            }
            parent::tearDown();
        }

        /**
         * @param array<string, mixed> $config
         */
        private function providerWithConfig(array $config): ConfigProviderInterface
        {
            return new class ($config) implements ConfigProviderInterface {
                /** @param array<string, mixed> $config */
                public function __construct(private readonly array $config)
                {
                }

                #[Override]
                public function getModuleName(): string
                {
                    return 'framework';
                }

                #[Override]
                public function getConfig(): array
                {
                    return $this->config;
                }
            };
        }

        /**
         * @return array<string, array{int|string}>
         */
        public static function negativeMaxRefsProvider(): array
        {
            return [
                'negative integer' => [-5],
                'negative numeric string' => ['-1'],
            ];
        }

        /**
         * @param int|string $rawValue
         */
        #[DataProvider('negativeMaxRefsProvider')]
        public function testBootClampsNegativeMaxCrossModuleRefs(int|string $rawValue): void
        {
            $app = new Application();
            $app->addProvider($this->providerWithConfig([
                'container' => ['max_cross_module_refs' => $rawValue],
                'middleware.stack' => [],
                'routes' => [],
            ]));
            // Must not throw: the negative value is clamped to 0 at
            // Application.php L129 before it reaches configurePolicies().
            $app->boot();
            self::assertTrue($app->isBooted());
        }

        public function testShutdownSwallowsTelemetryShutdownException(): void
        {
            // Boot with an *enabled, valid* telemetry: the lazy factory
            // (Telemetry::fromEnvironment) runs during warmSingletons() and
            // needs a well-formed drain value to build the in-memory telemetry.
            putenv('ZEF_OTEL_ENABLED=1');
            putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=');
            putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=0');

            $app = new Application();
            $app->addProvider($this->providerWithConfig([
                'middleware.stack' => [],
                'routes' => [],
            ]));
            $app->boot();
            self::assertTrue($app->isBooted());

            // Now corrupt the drain value: Telemetry::shutdown() re-reads it at
            // call time via envIntRequired() and throws InvalidArgumentException,
            // which Application::shutdown() must swallow (Application.php L220).
            putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=not-an-int');
            $app->shutdown();
            $this->addToAssertionCount(1);
        }
    }
}
