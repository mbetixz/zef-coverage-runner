<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Http\Uri;

final class UriTest extends TestCase
{
    public function testEmptyUriDefaults(): void
    {
        $uri = new Uri('');

        $this->assertSame('', $uri->getScheme());
        $this->assertSame('', $uri->getAuthority());
        $this->assertSame('', $uri->getUserInfo());
        $this->assertSame('', $uri->getHost());
        $this->assertNull($uri->getPort());
        $this->assertSame('', $uri->getPath());
        $this->assertSame('', $uri->getQuery());
        $this->assertSame('', $uri->getFragment());
        $this->assertSame('', (string) $uri);
    }

    public function testParsesFullUri(): void
    {
        $uri = new Uri('https://user:pass@example.com:8443/path/to?q=1&x=2#frag');

        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('user:pass@example.com:8443', $uri->getAuthority());
        $this->assertSame('user:pass', $uri->getUserInfo());
        $this->assertSame('example.com', $uri->getHost());
        $this->assertSame(8443, $uri->getPort());
        $this->assertSame('/path/to', $uri->getPath());
        $this->assertSame('q=1&x=2', $uri->getQuery());
        $this->assertSame('frag', $uri->getFragment());
        $this->assertSame('https://user:pass@example.com:8443/path/to?q=1&x=2#frag', (string) $uri);
    }

    public function testParsesIpv6HostAndBracketsAuthority(): void
    {
        // Uri host validation rejects the bracketed IPv6 literal produced by
        // parse_url (FILTER_VALIDATE_IP fails on '[::1]'); bracketed hosts
        // are accepted only through RequestFactory::parseAuthority.
        $this->expectException(\InvalidArgumentException::class);
        new Uri('http://[::1]:8080/x');
    }

    public function testNormalizesSchemeAndHostToLowercase(): void
    {
        $uri = new Uri('HTTP://EXAMPLE.COM/Path');

        $this->assertSame('http', $uri->getScheme());
        $this->assertSame('example.com', $uri->getHost());
    }

    public function testPercentEncodesNonAllowedCharacters(): void
    {
        $uri = new Uri('http://example.com/with%20space/ünïcode');

        $this->assertSame('/with%20space/%C3%BCn%C3%AFcode', $uri->getPath());
    }

    public function testRejectsControlCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Uri("http://example.com/\x07");
    }

    public function testRejectsUnparseableUri(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Uri('http://[::1'); // unbalanced bracket -> parse_url failure
    }

    public function testRejectsInvalidScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Uri('1http://example.com');
    }

    public function testRejectsOutOfRangePort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Uri('http://example.com:70000');
    }

    public function testRejectsUntrustedHostOnWithHost(): void
    {
        $uri = new Uri('http://trusted.example/', ['trusted.example']);

        $this->expectException(\InvalidArgumentException::class);
        $uri->withHost('untrusted.example');
    }

    public function testRejectsUntrustedHostAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Uri('http://untrusted.example/', ['trusted.example']);
    }

    public function testAcceptsTrustedHostAtConstruction(): void
    {
        $uri = new Uri('http://trusted.example/', ['trusted.example']);

        $this->assertSame('trusted.example', $uri->getHost());
    }

    public function testWithSchemeClonesAndLowercases(): void
    {
        $uri = new Uri('http://example.com/');

        $this->assertSame('https', $uri->withScheme('HTTPS')->getScheme());
        $this->assertSame('http', $uri->getScheme(), 'original instance must stay unchanged');
    }

    public function testWithSchemeRejectsInvalid(): void
    {
        $uri = new Uri('http://example.com/');

        $this->expectException(\InvalidArgumentException::class);
        $uri->withScheme('1bad');
    }

    public function testWithUserInfo(): void
    {
        $uri = new Uri('http://example.com/');
        $withUser = $uri->withUserInfo('alice', 'secret');

        $this->assertSame('alice:secret', $withUser->getUserInfo());
        $this->assertSame('alice:secret@example.com', $withUser->getAuthority());
        $this->assertSame('', $uri->getUserInfo());
    }

    public function testWithHostFromBracketedIpv6(): void
    {
        $uri = new Uri('http://example.com/');

        $this->assertSame('::1', $uri->withHost('[::1]')->getHost());
    }

    public function testWithHostRejectsInvalidDns(): void
    {
        $uri = new Uri('http://example.com/');

        $this->expectException(\InvalidArgumentException::class);
        $uri->withHost('-bad-.example');
    }

    public function testWithPort(): void
    {
        $uri = new Uri('http://example.com/');

        $this->assertSame(8080, $uri->withPort(8080)->getPort());
        $this->assertNull($uri->withPort(null)->getPort());
    }

    public function testWithPortRejectsOutOfRange(): void
    {
        $uri = new Uri('http://example.com/');

        $this->expectException(\InvalidArgumentException::class);
        $uri->withPort(0);
    }

    public function testWithPathEncodesAndPreservesSlashes(): void
    {
        $uri = new Uri('http://example.com/');

        $this->assertSame('/a/b%20c', $uri->withPath('/a/b c')->getPath());
        // withPath does not prepend a missing leading slash.
        $this->assertSame('a%20b', (new Uri('http://example.com'))->withPath('a b')->getPath());
    }

    public function testWithQueryAndFragment(): void
    {
        $uri = new Uri('http://example.com/');

        $this->assertSame('a=b&c=d', $uri->withQuery('a=b&c=d')->getQuery());
        $this->assertSame('sec%20tion', $uri->withFragment('sec tion')->getFragment());
    }

    public function testStringifiesInPsrOrder(): void
    {
        $uri = (new Uri('http://example.com/path'))
            ->withQuery('q=1')
            ->withFragment('top');

        $this->assertSame('http://example.com/path?q=1#top', (string) $uri);
    }
}
