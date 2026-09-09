<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\PayloadTooLargeException;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\UploadedFile;
use Zef\Framework\Validation\RouteConstraintValidator;

final class RequestFactoryGlobalsDetailedTest extends TestCase
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

    public function testDefaultsWhenServerMinimal(): void
    {
        $this->setServer([
            'HTTP_HOST' => 'localhost',
        ]);

        $request = RequestFactory::fromGlobals();

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $this->assertSame('http://localhost/', (string) $request->getUri());
    }

    public function testFrontControllerScriptNameStrippedFromPath(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/index.php/users/1',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/var/www/index.php',
            'HTTP_HOST' => 'example.com',
        ]);

        $request = RequestFactory::fromGlobals();
        $this->assertSame('/users/1', $request->getUri()->getPath());
    }

    public function testFrontControllerExactScriptPathBecomesRoot(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/index.php',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/var/www/index.php',
            'HTTP_HOST' => 'example.com',
        ]);

        $request = RequestFactory::fromGlobals();
        $this->assertSame('/', $request->getUri()->getPath());
    }

    public function testScriptNameDifferentFromFilenameKeepsPath(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/public/users',
            'SCRIPT_NAME' => '/public/index.php',
            'SCRIPT_FILENAME' => '/var/www/index.php',
            'HTTP_HOST' => 'example.com',
        ]);

        // SCRIPT_NAME basename (index.php) != SCRIPT_FILENAME basename (index.php)
        // -> front-controller stripping applies only when they match.
        $request = RequestFactory::fromGlobals();
        $this->assertSame('/public/users', $request->getUri()->getPath());
    }

    public function testDefaultPortOmittedFromAuthority(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '80',
        ]);

        $request = RequestFactory::fromGlobals();
        $this->assertNull($request->getUri()->getPort());
        $this->assertSame('example.com', $request->getUri()->getHost());
    }

    public function testNonDefaultPortIncluded(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '8080',
        ]);

        $request = RequestFactory::fromGlobals();
        $this->assertSame(8080, $request->getUri()->getPort());
    }

    public function testHttpsDefaultPort443Omitted(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTPS' => 'on',
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '443',
        ]);

        $request = RequestFactory::fromGlobals();
        $this->assertSame('https', $request->getUri()->getScheme());
        $this->assertNull($request->getUri()->getPort());
    }

    public function testMalformedAuthorityRejected(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'exa mple.com',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed Host');
        RequestFactory::fromGlobals();
    }

    public function testIpv6AuthorityAccepted(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => '[::1]:8080',
        ]);

        $request = RequestFactory::fromGlobals();
        $this->assertSame('::1', $request->getUri()->getHost());
        $this->assertSame(8080, $request->getUri()->getPort());
    }

    public function testMalformedIpv6AuthorityRejected(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => '[::1',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        RequestFactory::fromGlobals();
    }

    public function testTrustedProxyCidrMatching(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_HOST' => 'internal.example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $request = RequestFactory::fromGlobals(
            trustedProxies: ['10.0.0.0/8'],
        );

        $this->assertSame('internal.example.com', $request->getUri()->getHost());
        $this->assertSame('https', $request->getUri()->getScheme());
    }

    public function testTrustedProxyIpv6Cidr(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '2001:db8::5',
            'HTTP_X_FORWARDED_HOST' => 'v6.example.com',
        ]);

        $request = RequestFactory::fromGlobals(
            trustedProxies: ['2001:db8::/32'],
        );

        $this->assertSame('v6.example.com', $request->getUri()->getHost());
    }

    public function testInvalidCidrEntryIgnored(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_HOST' => 'evil.example.com',
            'HTTP_HOST' => 'example.com',
        ]);

        // '999.999.999.999/24' is not parseable -> proxy treated as untrusted,
        // so the forwarded host is ignored and HTTP_HOST wins.
        $request = RequestFactory::fromGlobals(trustedProxies: ['999.999.999.999/24']);

        $this->assertSame('example.com', $request->getUri()->getHost());
    }

    public function testMultipleForwardedHostsUsesFirst(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '10.0.0.9',
            'HTTP_X_FORWARDED_HOST' => 'first.example.com, second.example.com',
        ]);

        $request = RequestFactory::fromGlobals(trustedProxies: ['10.0.0.0/8']);
        $this->assertSame('first.example.com', $request->getUri()->getHost());
    }

    public function testQueryStringPreserved(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/search?q=php&page=2',
            'HTTP_HOST' => 'example.com',
        ]);

        $request = RequestFactory::fromGlobals();
        $this->assertSame('q=php&page=2', $request->getUri()->getQuery());
    }

    public function testUploadedFileFromFilesSuperglobal(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'zef-glob-');
        $this->assertNotFalse($file);
        file_put_contents($file, 'upload-content');

        $this->setServer(['HTTP_HOST' => 'example.com']);
        $_FILES = [
            'avatar' => [
                'name' => 'avatar.txt',
                'type' => 'text/plain',
                'tmp_name' => $file,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen('upload-content'),
            ],
        ];

        try {
            $request = RequestFactory::fromGlobals();
            $uploads = $request->getUploadedFiles();
            $this->assertArrayHasKey('avatar', $uploads);
            $this->assertInstanceOf(UploadedFileInterface::class, $uploads['avatar']);
            $this->assertSame('upload-content', (string) $uploads['avatar']->getStream());
        } finally {
            @unlink($file);
        }
    }

    public function testUploadedFileErrorWithoutTmpNameGetsEmptyStream(): void
    {
        $this->setServer(['HTTP_HOST' => 'example.com']);
        $_FILES = [
            'doc' => [
                'name' => 'doc.txt',
                'type' => 'text/plain',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_NO_FILE,
                'size' => 0,
            ],
        ];

        $request = RequestFactory::fromGlobals();
        $uploads = $request->getUploadedFiles();
        $this->assertInstanceOf(UploadedFileInterface::class, $uploads['doc']);
        $this->assertSame(UPLOAD_ERR_NO_FILE, $uploads['doc']->getError());
    }

    public function testNonUploadFilesEntryPassesThrough(): void
    {
        $this->setServer(['HTTP_HOST' => 'example.com']);
        $_FILES = [
            'plain' => 'not-an-upload-array',
        ];

        $request = RequestFactory::fromGlobals();
        $uploads = $request->getUploadedFiles();
        $this->assertSame('not-an-upload-array', $uploads['plain']);
    }

    public function testLegacyNestedUploadLayout(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'zef-legacy-');
        $this->assertNotFalse($file);
        file_put_contents($file, 'nested');

        $this->setServer(['HTTP_HOST' => 'example.com']);
        $_FILES = [
            'gallery' => [
                'name' => ['a.txt', 'b.txt'],
                'type' => ['text/plain', 'text/plain'],
                'tmp_name' => [$file, ''],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
                'size' => [6, 0],
            ],
        ];

        try {
            $request = RequestFactory::fromGlobals();
            $uploads = $request->getUploadedFiles();
            $gallery = $uploads['gallery'];

            $this->assertIsArray($gallery);
            $this->assertArrayHasKey(0, $gallery);
            $this->assertArrayHasKey(1, $gallery);
            $this->assertInstanceOf(UploadedFileInterface::class, $gallery[0]);
            $this->assertSame('nested', (string) $gallery[0]->getStream());
            $this->assertInstanceOf(UploadedFileInterface::class, $gallery[1]);
            $this->assertSame(UPLOAD_ERR_NO_FILE, $gallery[1]->getError());
        } finally {
            @unlink($file);
        }
    }

    public function testMalformedRequestTargetWithCrLfRejected(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => "/bad\r\npath",
            'HTTP_HOST' => 'example.com',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid request target');
        RequestFactory::fromGlobals();
    }

    public function testOversizedContentLengthHeaderRejected(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
            'CONTENT_LENGTH' => '999999999',
        ]);

        $this->expectException(PayloadTooLargeException::class);
        RequestFactory::fromGlobals(bodyPolicy: new RequestBodyPolicy(100));
    }

    public function testContentLengthHeaderSurfacesAsHeader(): void
    {
        $this->setServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => '12',
        ]);

        $request = RequestFactory::fromGlobals();
        $this->assertSame('12', $request->getHeaderLine('content-length'));
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('content-type'));
    }

    public function testCustomConstraintEvaluationFailureWrapped(): void
    {
        $validator = new RouteConstraintValidator();

        $this->expectException(InvalidConfigurationException::class);
        $validator->assertKnown('no_such_constraint');
    }

    public function testRouteConstraintValidatorRejectsOversizedRegex(): void
    {
        $validator = new RouteConstraintValidator();

        $this->expectException(InvalidConfigurationException::class);
        $validator->addCustom('big', str_repeat('a', 3000));
    }
}
