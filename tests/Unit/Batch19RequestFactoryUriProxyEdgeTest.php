<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Http\RequestFactory;

/**
 * Batch 19 coverage: RequestFactory URI/proxy edge paths — malformed
 * REQUEST_URI (parse_url failure), front-controller trailing-slash path
 * collapse, bare-dot authority rejection, trusted-proxy exact-match and the
 * CIDR classifier branches (non-CIDR entry, non-digit prefix, out-of-range
 * prefix, partial-byte prefix).
 */
final class Batch19RequestFactoryUriProxyEdgeTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];
    /** @var array<string, mixed> */
    private array $files = [];

    #[Override]
    protected function setUp(): void
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        $this->server = $server;
        /** @var array<string, mixed> $files */
        $files = $_FILES;
        $this->files = $files;
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
    }

    #[Override]
    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_FILES = $this->files;
    }

    /** @param array<string, mixed> $server */
    private function setServer(array $server): void
    {
        $_SERVER = $server;
    }

    public function testFrontControllerTrailingSlashCollapsesToRoot(): void
    {
        // REQUEST_URI '/index.php/' == scriptPath . '/' -> strip yields ''
        // which must be normalized back to '/'.
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/index.php/',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/var/www/index.php',
            'HTTP_HOST' => 'example.com',
        ]);

        $request = RequestFactory::fromGlobals();
        $this->assertSame('/', $request->getUri()->getPath());
    }

    public function testBareDotAuthorityRejected(): void
    {
        // Host '.' passes the raw character filter, then rtrim('.') leaves an
        // empty host -> isValidDnsHost() false -> malformed authority.
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => '.',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        RequestFactory::fromGlobals();
    }

    public function testTrustedProxyExactAddressMatch(): void
    {
        // Exact (non-CIDR) trusted-proxy entry must match REMOTE_ADDR verbatim.
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_X_FORWARDED_HOST' => 'internal.example.com',
        ]);

        $request = RequestFactory::fromGlobals(trustedProxies: ['203.0.113.7']);
        $this->assertSame('internal.example.com', $request->getUri()->getHost());
    }

    public function testUntrustedNonCidrProxyEntryIgnored(): void
    {
        // A bare IP entry that does not equal REMOTE_ADDR contains no '/' so
        // the CIDR branch is skipped (continue) and the proxy stays untrusted.
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_HOST' => 'evil.example.com',
            'HTTP_HOST' => 'example.com',
        ]);

        $request = RequestFactory::fromGlobals(trustedProxies: ['192.168.1.1']);
        $this->assertSame('example.com', $request->getUri()->getHost());
    }

    public function testNonDigitCidrPrefixIgnored(): void
    {
        // CIDR entry with a non-numeric prefix is malformed and skipped.
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_HOST' => 'evil.example.com',
            'HTTP_HOST' => 'example.com',
        ]);

        $request = RequestFactory::fromGlobals(trustedProxies: ['10.0.0.0/abc']);
        $this->assertSame('example.com', $request->getUri()->getHost());
    }

    public function testOutOfRangeCidrPrefixIgnored(): void
    {
        // IPv4 prefix 33 exceeds 32 bits -> classifier returns false.
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_HOST' => 'evil.example.com',
            'HTTP_HOST' => 'example.com',
        ]);

        $request = RequestFactory::fromGlobals(trustedProxies: ['10.0.0.0/33']);
        $this->assertSame('example.com', $request->getUri()->getHost());
    }

    public function testPartialByteCidrPrefixTrustsMatchingHost(): void
    {
        // /25 leaves 1 remaining bit: the final byte is compared against a
        // 0x80 mask — 10.0.0.5 shares the top bit with 10.0.0.0/25.
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_HOST' => 'internal.example.com',
        ]);

        $request = RequestFactory::fromGlobals(trustedProxies: ['10.0.0.0/25']);
        $this->assertSame('internal.example.com', $request->getUri()->getHost());
    }
}
