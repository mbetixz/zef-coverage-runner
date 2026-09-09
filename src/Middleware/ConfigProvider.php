<?php

declare(strict_types=1);

namespace Zef\Middleware {
    use Zef\Framework\Config\ConfigProviderInterface;
    use Zef\Framework\Security\InMemoryRateLimiter;
    use Zef\Framework\Security\SecurityPolicy;
    use Zef\Framework\Security\SecurityRuntimeMiddleware;

    final class ConfigProvider implements ConfigProviderInterface
    {
        public function __construct(private readonly bool $devMode = false)
        {
        }
        #[\Override]
        public function getModuleName(): string
        {
            return 'middleware';
        }
        #[\Override]
        public function getConfig(): array
        {
            $devMode = $this->devMode;
            return ['services' => [
                'middleware.error' => ['factory' => static function (\Psr\Container\ContainerInterface $c) use ($devMode): GlobalErrorHandler {
                    $logger = $c->get(\Psr\Log\LoggerInterface::class);
                    if (!$logger instanceof \Psr\Log\LoggerInterface) {
                        throw new \RuntimeException('LoggerInterface service must implement Psr\Log\LoggerInterface.');
                    }
                    return new GlobalErrorHandler($logger, new ErrorResponseFactory($devMode));
                },'deps' => [\Psr\Log\LoggerInterface::class]],
                'middleware.timing' => ['factory' => static fn () => new TimingMiddleware(),'deps' => []],
                'middleware.cors' => ['factory' => static fn () => self::buildCors(),'deps' => []],
                'middleware.security' => ['factory' => static function (): SecurityHeadersMiddleware {
                    $hsts = filter_var((string) (getenv('ZEF_SECURITY_HSTS') ?: '0'), FILTER_VALIDATE_BOOL);
                    $csp = filter_var((string) (getenv('ZEF_SECURITY_CSP') ?: '0'), FILTER_VALIDATE_BOOL);
                    return new SecurityHeadersMiddleware(['hsts' => $hsts, 'csp' => $csp]);
                },'deps' => []],
                'middleware.security.runtime' => ['factory' => static function (): SecurityRuntimeMiddleware {
                    $policy = SecurityPolicy::fromEnvironment();
                    $rateLimiter = self::buildRateLimiter($policy);
                    return new SecurityRuntimeMiddleware($policy, $rateLimiter);
                },'deps' => []],
            ],'stack' => ['middleware.error','middleware.security.runtime','middleware.security','middleware.timing','middleware.cors']];
        }

        /**
         * Selects the rate-limit store from ZEF_RATE_LIMIT_STORE.
         *   memory (default) -> InMemoryRateLimiter (per-process, documented)
         *   apcu             -> ApcuRateLimiter (shared across the worker pool)
         *   redis            -> RedisRateLimiter over RedisSharedRateLimitStore
         * Any other value falls back to the in-memory limiter with a warning
         * instead of failing the whole middleware stack.
         */
        private static function buildRateLimiter(\Zef\Framework\Security\SecurityPolicy $policy): \Zef\Framework\Security\RateLimiterInterface
        {
            $store = strtolower(trim((string) (getenv('ZEF_RATE_LIMIT_STORE') ?: 'memory')));
            try {
                return match ($store) {
                    'apcu' => new \Zef\Framework\Security\ApcuRateLimiter($policy->rateLimitMaxKeys),
                    'redis' => new \Zef\Framework\Security\RedisRateLimiter(new \Zef\Framework\Security\RedisSharedRateLimitStore(new \Redis()), $policy->rateLimitMaxKeys),
                    default => new \Zef\Framework\Security\InMemoryRateLimiter($policy->rateLimitMaxKeys),
                };
            } catch (\Throwable $e) {
                error_log('[ZEF][security] Rate-limit store "' . $store . '" unavailable (' . $e->getMessage() . '); falling back to in-memory per-process limiter.');
                return new \Zef\Framework\Security\InMemoryRateLimiter($policy->rateLimitMaxKeys);
            }
        }

        /**
         * Builds the CORS middleware from the environment allowlist:
         *   ZEF_CORS_ORIGIN             comma-separated allowlist (exact origin)
         *   ZEF_CORS_ORIGIN_ANY=1       allow '*' (public, credential-less only)
         * When neither is configured the middleware defaults to deny (no CORS
         * headers), which is the safe default for API deployments.
         *
         * Production guard (R4-A3 / audit S-04):
         *   - ZEF_CORS_ORIGIN_ANY=1 is BLOCKED in production (ZEF_APP_ENV=production).
         *     Wildcard CORS must never be used with a production deployment;
         *     set an explicit allowlist via ZEF_CORS_ORIGIN instead.
         *   - No allowlist in production emits a prominent warning so operators
         *     are aware that CORS is in deny-all mode (safe, but intentional).
         */
        private static function buildCors(): CorsMiddleware
        {
            $isProduction = strtolower(trim((string) (getenv('ZEF_APP_ENV') ?: 'development'))) === 'production';

            if (filter_var((string) (getenv('ZEF_CORS_ORIGIN_ANY') ?: '0'), FILTER_VALIDATE_BOOL)) {
                if ($isProduction) {
                    throw new \RuntimeException(
                        '[ZEF][security] ZEF_CORS_ORIGIN_ANY=1 is not allowed in production (ZEF_APP_ENV=production). '
                        . 'Set an explicit origin allowlist via ZEF_CORS_ORIGIN instead.'
                    );
                }
                return new CorsMiddleware(['*']);
            }

            $raw = getenv('ZEF_CORS_ORIGIN');
            $origins = [];
            if ($raw !== false && trim((string) $raw) !== '') {
                $origins = array_values(array_filter(array_map('trim', explode(',', (string) $raw)), static fn (string $v): bool => $v !== ''));
            }

            if ($isProduction && $origins === []) {
                error_log(
                    '[ZEF][security] No CORS allowlist configured in production (ZEF_APP_ENV=production). '
                    . 'CORS is in deny-all mode. Set ZEF_CORS_ORIGIN to an explicit comma-separated allowlist '
                    . 'if cross-origin requests are required.'
                );
            }

            return new CorsMiddleware($origins);
        }
    }
}
