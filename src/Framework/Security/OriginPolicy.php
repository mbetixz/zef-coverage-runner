<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    /**
     * Origin allowlist policy (CSRF/origin defence in depth, M-5).
     *
     * Header consumption contract (Origin, request):
     * - assertAllowed() treats a null/empty origin as allowed: non-browser
     *   clients (curl, server-to-server, RoadRunner internal calls) do not send
     *   an Origin header, and the CSRF token already covers state mutation.
     * - Before comparison, the origin is normalized by normalizeOrigin(): the
     *   literal string 'null' is preserved; scheme/host are lower-cased; default
     *   ports for the scheme (80 for http, 443 for https) are dropped; IPv6
     *   hosts are re-bracketed; CR/LF or embedded userinfo/path/query/fragment,
     *   non-http(s) schemes and malformed hosts/ports are rejected with
     *   InvalidArgumentException/InvalidConfigurationException — so a raw
     *   attacker-supplied Origin value never reaches the allowlist comparison
     *   or any response logic unsanitized.
     */
    final class OriginPolicy
    {
        /** @param list<string> $allowedOrigins */
        public static function assertAllowed(?string $origin, array $allowedOrigins): void
        {
            if ($origin === null || $origin === '') {
                return;
            }
            $normalized = self::normalizeOrigin($origin);
            if (!in_array($normalized, $allowedOrigins, true)) {
                throw new \Zef\Framework\Exception\InvalidConfigurationException('Origin is not allowed.');
            }
        }

        /**
         * Normalizes an Origin header value into its canonical comparison form.
         *
         * Grammar: scheme '://' host [:port] where scheme is http/https
         * (case-insensitive, output lower-cased), host is a valid DNS name or
         * IP literal (IPv6 re-bracketed), and the port, when present, must be
         * >= 1 and is omitted when it equals the scheme's default (80/443). The
         * literal value 'null' is returned unchanged (browser sandbox origin).
         *
         * @throws \InvalidArgumentException on malformed input (CR/LF, bad
         *                                   scheme/host/port, embedded
         *                                   userinfo/path/query/fragment).
         */
        public static function normalizeOrigin(string $origin): string
        {
            $origin = trim($origin);
            if ($origin === 'null') {
                return 'null';
            }
            if (preg_match('/[\r\n]/', $origin) === 1) {
                throw new \InvalidArgumentException('Malformed Origin header.');
            }
            $parts = parse_url($origin);
            if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['user'], $parts['pass']) || isset($parts['path']) && $parts['path'] !== '' || isset($parts['query']) || isset($parts['fragment'])) {
                throw new \InvalidArgumentException('Malformed Origin header.');
            }
            $scheme = strtolower((string) $parts['scheme']);
            $host = strtolower((string) $parts['host']);
            $port = isset($parts['port']) ? (int) $parts['port'] : null;
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new \InvalidArgumentException('Unsupported Origin scheme.');
            }
            if (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/', $host) !== 1) {
                throw new \InvalidArgumentException('Malformed Origin host.');
            }
            if ($port !== null && $port < 1) {
                throw new \InvalidArgumentException('Malformed Origin port.');
            }
            if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
                $port = null;
            }
            return $scheme . '://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ($port === null ? '' : ':' . $port);
        }
    }
}
