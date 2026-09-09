<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    use Psr\Http\Message\ServerRequestInterface;

    /**
     * Resolves the effective client IP address from server params and
     * forwarded headers, honoring a trusted-proxy allowlist.
     *
     * Header consumption contract:
     * - REMOTE_ADDR (server param, not a header): the direct peer address.
     *   It is trusted as the client only when it is a non-empty, valid IP that
     *   matches a configured trusted proxy; otherwise it is returned (after
     *   sanitize()) and forwarded headers are never consulted.
     * - X-Forwarded-For (request): consulted ONLY when REMOTE_ADDR is a trusted
     *   proxy. The header is split on ',' and trimmed; the FIRST candidate that
     *   passes FILTER_VALIDATE_IP is returned. If no candidate is a valid IP
     *   the trusted proxy's REMOTE_ADDR itself is returned instead. Values are
     *   never used raw: every returned address passes FILTER_VALIDATE_IP, and
     *   anything invalid is replaced with the '0.0.0.0' sentinel.
     *
     * @param list<string> $trustedProxies  IPs or CIDR blocks allowed to supply
     *                                      X-Forwarded-For (see isTrustedProxy).
     */
    final class ClientAddressResolver
    {
        /** @param list<string> $trustedProxies */
        public static function resolve(ServerRequestInterface $request, array $trustedProxies = []): string
        {
            $remoteValue = $request->getServerParams()['REMOTE_ADDR'] ?? null;
            $remote = is_string($remoteValue) ? trim($remoteValue) : '';
            if ($remote === '' || !self::isTrustedProxy($remote, $trustedProxies)) {
                return self::sanitize($remote ?: '0.0.0.0');
            }
            $forwarded = $request->getHeaderLine('X-Forwarded-For');
            if ($forwarded !== '') {
                foreach (array_map('trim', explode(',', $forwarded)) as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                        return $candidate;
                    }
                }
            }
            return self::sanitize($remote ?: '0.0.0.0');
        }

        /**
         * Validates and, when valid, returns the address unchanged; anything
         * that is not a syntactically valid IP is replaced with the '0.0.0.0'
         * sentinel so callers never receive an unvalidated string.
         */
        private static function sanitize(string $ip): string
        {
            return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
        }

        /** @param list<string> $trusted */
        private static function isTrustedProxy(string $ip, array $trusted): bool
        {
            foreach ($trusted as $entry) {
                $entry = trim((string) $entry);
                if ($entry === '') {
                    continue;
                }
                if (filter_var($entry, FILTER_VALIDATE_IP) !== false && hash_equals($entry, $ip)) {
                    return true;
                }
                if (!str_contains($entry, '/')) {
                    continue;
                }
                [$network, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
                $ipBinary = @inet_pton($ip);
                $networkBinary = @inet_pton((string) $network);
                if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
                    continue;
                }
                $bits = (int) $prefix;
                $maxBits = strlen($ipBinary) * 8;
                if ($bits < 0 || $bits > $maxBits) {
                    continue;
                }
                $bytes = intdiv($bits, 8);
                $remaining = $bits % 8;
                if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
                    continue;
                }
                if ($remaining > 0) {
                    $mask = (0xFF << (8 - $remaining)) & 0xFF;
                    if ((ord($ipBinary[$bytes]) & $mask) !== (ord($networkBinary[$bytes]) & $mask)) {
                        continue;
                    }
                }
                return true;
            }
            return false;
        }
    }
}
