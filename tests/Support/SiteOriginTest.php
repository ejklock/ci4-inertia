<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Ejklock\Ci4Inertia\Support\SiteOrigin;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies `Ejklock\Ci4Inertia\Support\SiteOrigin`: a URL is returned only when its scheme,
 * effective port and host are the site's own base URL or an allowed hostname.
 *
 * @internal
 */
final class SiteOriginTest extends CIUnitTestCase
{
    private string $originalBaseUrl;

    /**
     * @var list<string>
     */
    private array $originalAllowedHostnames;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBaseUrl          = config(App::class)->baseURL;
        $this->originalAllowedHostnames = config(App::class)->allowedHostnames;
    }

    protected function tearDown(): void
    {
        config(App::class)->baseURL          = $this->originalBaseUrl;
        config(App::class)->allowedHostnames = $this->originalAllowedHostnames;

        parent::tearDown();
    }

    #[DataProvider('provideTheUrlIsReturnedOnlyWhenItIsOnTheSiteOrigin')]
    public function testTheUrlIsReturnedOnlyWhenItIsOnTheSiteOrigin(string $baseUrl, string $url, bool $onSite): void
    {
        config(App::class)->baseURL = $baseUrl;

        $this->assertSame($onSite ? $url : null, SiteOrigin::urlOrNull($url));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideTheUrlIsReturnedOnlyWhenItIsOnTheSiteOrigin(): iterable
    {
        yield 'same origin' => ['http://example.com/', 'http://example.com/a?b=1', true];

        yield 'host in upper case' => ['http://example.com/', 'http://EXAMPLE.com/a', true];

        yield 'other host' => ['http://example.com/', 'http://evil.example/a', false];

        yield 'suffix trick' => ['http://example.com/', 'http://example.com.evil.example/', false];

        yield 'other scheme' => ['http://example.com/', 'https://example.com/a', false];

        yield 'other port' => ['http://example.com/', 'http://example.com:8099/a', false];

        yield 'explicit default http port' => ['http://example.com/', 'http://example.com:80/a', true];

        yield 'https default port against an http base' => ['http://example.com/', 'https://example.com:443/a', false];

        yield 'https explicit default port' => ['https://example.com/', 'https://example.com:443/a', true];

        yield 'https implicit port against an explicit default' => ['https://example.com:443/', 'https://example.com/a', true];

        yield 'userinfo' => ['http://example.com/', 'http://example.com:80@evil.example/', false];

        yield 'scheme relative' => ['http://example.com/', '//evil.example/x', false];

        yield 'javascript scheme' => ['http://example.com/', 'javascript:alert(1)', false];

        yield 'unparseable' => ['http://example.com/', 'http://:::', false];

        yield 'backslash hiding the real host' => ['http://example.com/', 'http://evil.example\\@example.com/', false];

        yield 'backslash in the path' => ['http://example.com/', 'http://example.com/a\\b', false];

        yield 'control character in the path' => ['http://example.com/', "http://example.com/\t", false];

        yield 'delete character in the path' => ['http://example.com/', "http://example.com/a\x7Fb", false];

        yield 'encoded backslash' => ['http://example.com/', 'http://example.com/a%5Cb', true];
    }

    public function testAnAllowedHostnameIsOnSiteWhateverItsCase(): void
    {
        config(App::class)->allowedHostnames = ['ALT.example'];

        $this->assertSame('http://alt.example/a', SiteOrigin::urlOrNull('http://alt.example/a'));
        $this->assertSame('http://Alt.EXAMPLE/a', SiteOrigin::urlOrNull('http://Alt.EXAMPLE/a'));
    }
}
