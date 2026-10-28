<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use CodeIgniter\Config\BaseService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\App;
use Config\Cookie as CookieConfig;
use Config\Security;
use Config\Session as SessionConfig;
use Ejklock\Ci4Inertia\Filters\InertiaCsrfFilter;
use Ejklock\Ci4Inertia\Filters\XsrfTokenCookieFilter;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies `Ejklock\Ci4Inertia\Filters\InertiaCsrfFilter` and `XsrfTokenCookieFilter` against the
 * stock app: the `303` back to an on-site page with an `error` flash for a refused Inertia
 * visit, the `SecurityException` for every other request, and the readable `XSRF-TOKEN`
 * cookie on successful responses.
 *
 * @internal
 */
final class CsrfFiltersTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const SESSION_HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const OTHER_HASH   = '0f9e8d7c6b5a49382716f5e4d3c2b1a0';
    private const NOTICE       = 'Page expired, please try again.';

    private Security $security;

    /**
     * @var array<string, mixed>
     */
    private array $originalSecurity;

    /**
     * @var array<string, string>
     */
    private array $originalAliases;

    private bool $originalCookieSecure;
    private string $originalCookiePath;
    private string $originalBaseUrl;

    /**
     * @var list<string>
     */
    private array $originalAllowedHostnames;

    private int $originalSessionExpiration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->security         = config(Security::class);
        $this->originalSecurity = [
            'csrfProtection' => $this->security->csrfProtection,
            'headerName'     => $this->security->headerName,
            'tokenRandomize' => $this->security->tokenRandomize,
            'regenerate'     => $this->security->regenerate,
            'redirect'       => $this->security->redirect,
        ];

        $this->security->csrfProtection = 'session';
        $this->security->headerName     = 'X-XSRF-TOKEN';
        $this->security->tokenRandomize = true;
        $this->security->regenerate     = false;
        $this->security->redirect       = false;

        $filters                      = config('Filters');
        $this->originalAliases        = $filters->aliases;
        $filters->aliases['csrf']     = InertiaCsrfFilter::class;
        $filters->aliases['xsrfcook'] = XsrfTokenCookieFilter::class;

        $this->originalCookieSecure      = config(CookieConfig::class)->secure;
        $this->originalCookiePath        = config(CookieConfig::class)->path;
        $this->originalBaseUrl           = config(App::class)->baseURL;
        $this->originalAllowedHostnames  = config(App::class)->allowedHostnames;
        $this->originalSessionExpiration = config(SessionConfig::class)->expiration;
    }

    protected function tearDown(): void
    {
        foreach ($this->originalSecurity as $property => $value) {
            $this->security->{$property} = $value;
        }

        config('Filters')->aliases               = $this->originalAliases;
        config(CookieConfig::class)->secure      = $this->originalCookieSecure;
        config(CookieConfig::class)->path        = $this->originalCookiePath;
        config(App::class)->baseURL              = $this->originalBaseUrl;
        config(App::class)->allowedHostnames     = $this->originalAllowedHostnames;
        config(SessionConfig::class)->expiration = $this->originalSessionExpiration;

        $this->resetPerRequestServices();
        service('superglobals')->unsetServer('HTTP_REFERER');

        parent::tearDown();
    }

    #[DataProvider('unsafeMethods')]
    public function testHeaderTokenEqualToTheCookieIssuedForTheSessionSucceeds(string $method): void
    {
        $token = $this->issuedToken(self::SESSION_HASH);

        $this->send($method, '/probe', ['X-Inertia' => 'true', 'X-XSRF-TOKEN' => $token])->assertStatus(200);
    }

    #[DataProvider('unsafeMethods')]
    public function testInertiaRequestWithAMissingHeaderTokenIsRedirectedBackWithAnExpiredNotice(string $method): void
    {
        $this->issuedToken(self::SESSION_HASH);

        $this->assertRedirectedBackWithExpiredNotice($this->send($method, '/probe', ['X-Inertia' => 'true'], previousUrl: site_url('/login')));
    }

    #[DataProvider('unsafeMethods')]
    public function testInertiaRequestWithOneAlteredCharacterInTheHeaderTokenIsRedirectedBackWithAnExpiredNotice(string $method): void
    {
        $token   = $this->issuedToken(self::SESSION_HASH);
        $altered = substr($token, 0, -1) . ($token[-1] === '0' ? '1' : '0');

        $this->assertRedirectedBackWithExpiredNotice(
            $this->send($method, '/probe', ['X-Inertia' => 'true', 'X-XSRF-TOKEN' => $altered], previousUrl: site_url('/login')),
        );
    }

    #[DataProvider('unsafeMethods')]
    public function testInertiaRequestWithATokenIssuedForAnotherSessionIsRedirectedBackWithAnExpiredNotice(string $method): void
    {
        $foreign = $this->issuedToken(self::OTHER_HASH);

        $this->assertRedirectedBackWithExpiredNotice(
            $this->send($method, '/probe', ['X-Inertia' => 'true', 'X-XSRF-TOKEN' => $foreign], previousUrl: site_url('/login')),
        );
    }

    #[DataProvider('unsafeMethods')]
    public function testInertiaRequestEchoingAPlantedCookieIsRedirectedBackWithAnExpiredNoticeBecauseOnlyTheSessionIsTrusted(string $method): void
    {
        $planted = $this->issuedToken(self::OTHER_HASH);
        service('superglobals')->setCookie('XSRF-TOKEN', $planted);

        $this->assertRedirectedBackWithExpiredNotice(
            $this->send($method, '/probe', ['X-Inertia' => 'true', 'X-XSRF-TOKEN' => $planted], previousUrl: site_url('/login')),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeMethods(): iterable
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            yield $method => [$method];
        }
    }

    public function testInertiaRequestWithoutAPreviousUrlIsRedirectedToTheSiteRoot(): void
    {
        $response = $this->send('POST', '/probe', ['X-Inertia' => 'true']);

        $response->assertStatus(303);
        $response->assertRedirectTo(site_url('/'));
    }

    #[DataProvider('provideInertiaRequestWithAForeignRefererAndNoPreviousUrlIsRedirectedToTheSiteRoot')]
    public function testInertiaRequestWithAForeignRefererAndNoPreviousUrlIsRedirectedToTheSiteRoot(string $referer): void
    {
        service('superglobals')->setServer('HTTP_REFERER', $referer);

        $this->assertRedirectedBackWithExpiredNotice($this->send('POST', '/probe', ['X-Inertia' => 'true']), site_url('/'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInertiaRequestWithAForeignRefererAndNoPreviousUrlIsRedirectedToTheSiteRoot(): iterable
    {
        $base = parse_url(config(App::class)->baseURL);
        $host = $base['host'];

        yield 'other host' => ['https://evil.example/login'];

        yield 'scheme relative' => ['//evil.example/x'];

        yield 'suffix trick' => ['http://' . $host . '.evil.example/'];

        yield 'other port' => ['http://' . $host . ':8099/settings'];

        yield 'other scheme' => ['https://' . $host . ':' . ($base['port'] ?? 80) . '/settings'];

        yield 'javascript scheme' => ['javascript:alert(1)'];

        yield 'malformed' => ['http://:::'];

        yield 'userinfo' => ['http://' . $host . ':' . ($base['port'] ?? 80) . '@evil.example/'];

        yield 'backslash hiding the real host' => ['http://evil.example\\@' . $host . '/'];
    }

    public function testInertiaRequestWithAnOnSiteRefererAndNoPreviousUrlIsRedirectedThere(): void
    {
        service('superglobals')->setServer('HTTP_REFERER', site_url('/settings'));

        $this->assertRedirectedBackWithExpiredNotice($this->send('POST', '/probe', ['X-Inertia' => 'true']), site_url('/settings'));
    }

    public function testInertiaRequestWithAnOnSiteRefererCarryingAQueryStringIsRedirectedToTheWholeReferer(): void
    {
        $referer = site_url('/settings') . '?tab=profile&page=2';
        service('superglobals')->setServer('HTTP_REFERER', $referer);

        $this->assertRedirectedBackWithExpiredNotice($this->send('POST', '/probe', ['X-Inertia' => 'true']), $referer);
    }

    public function testInertiaRequestWithAnOnSiteRefererWhoseHostIsUpperCasedIsRedirectedThere(): void
    {
        $base    = parse_url(config(App::class)->baseURL);
        $referer = 'http://' . strtoupper($base['host']) . (isset($base['port']) ? ':' . $base['port'] : '') . '/settings';
        service('superglobals')->setServer('HTTP_REFERER', $referer);

        $this->assertRedirectedBackWithExpiredNotice($this->send('POST', '/probe', ['X-Inertia' => 'true']), $referer);
    }

    public function testInertiaRequestPrefersTheOnSiteRefererOverThePreviousUrl(): void
    {
        service('superglobals')->setServer('HTTP_REFERER', site_url('/dashboard'));

        $this->assertRedirectedBackWithExpiredNotice(
            $this->send('POST', '/probe', ['X-Inertia' => 'true'], previousUrl: site_url('/login')),
            site_url('/dashboard'),
        );
    }

    #[DataProvider('provideInertiaRequestWithAnOffSiteRefererAndAnOnSitePreviousUrlIsRedirectedToThePreviousUrl')]
    public function testInertiaRequestWithAnOffSiteRefererAndAnOnSitePreviousUrlIsRedirectedToThePreviousUrl(string $referer): void
    {
        service('superglobals')->setServer('HTTP_REFERER', $referer);

        $this->assertRedirectedBackWithExpiredNotice(
            $this->send('POST', '/probe', ['X-Inertia' => 'true'], previousUrl: site_url('/login')),
            site_url('/login'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInertiaRequestWithAnOffSiteRefererAndAnOnSitePreviousUrlIsRedirectedToThePreviousUrl(): iterable
    {
        $base = parse_url(config(App::class)->baseURL);
        $host = $base['host'];

        yield 'other host' => ['https://evil.example/login'];

        yield 'other scheme' => ['https://' . $host . ':' . ($base['port'] ?? 80) . '/settings'];

        yield 'other port' => ['http://' . $host . ':8099/settings'];

        yield 'malformed' => ['http://:::'];

        yield 'scheme relative' => ['//evil.example/x'];

        yield 'suffix trick' => ['http://' . $host . '.evil.example/'];

        yield 'javascript scheme' => ['javascript:alert(1)'];

        yield 'userinfo' => ['http://' . $host . ':' . ($base['port'] ?? 80) . '@evil.example/'];
    }

    public function testInertiaRequestWithAnUnparseableRefererIsRedirectedToTheSiteRoot(): void
    {
        service('superglobals')->setServer('HTTP_REFERER', 'http:///x');

        $this->assertRedirectedBackWithExpiredNotice($this->send('POST', '/probe', ['X-Inertia' => 'true']), site_url('/'));
    }

    public function testInertiaRequestWithAnUnparseableRefererFallsBackToTheOnSitePreviousUrl(): void
    {
        service('superglobals')->setServer('HTTP_REFERER', 'http:///x');

        $this->assertRedirectedBackWithExpiredNotice(
            $this->send('POST', '/probe', ['X-Inertia' => 'true'], previousUrl: site_url('/login')),
            site_url('/login'),
        );
    }

    public function testInertiaRequestPrefersTheOnSitePreviousUrlOverAForeignReferer(): void
    {
        service('superglobals')->setServer('HTTP_REFERER', 'https://evil.example/login');

        $this->assertRedirectedBackWithExpiredNotice(
            $this->send('POST', '/probe', ['X-Inertia' => 'true'], previousUrl: site_url('/login')),
            site_url('/login'),
        );
    }

    public function testInertiaRequestWithARefererOnAnAllowedHostnameWrittenInUpperCaseIsRedirectedThere(): void
    {
        config(App::class)->allowedHostnames = ['ALT.example'];
        service('superglobals')->setServer('HTTP_REFERER', 'http://alt.example/settings');

        $this->assertRedirectedBackWithExpiredNotice($this->send('POST', '/probe', ['X-Inertia' => 'true']), 'http://alt.example/settings');
    }

    #[DataProvider('provideInertiaRequestComparesTheRefererPortWithTheSitePortUsingTheSchemeDefault')]
    public function testInertiaRequestComparesTheRefererPortWithTheSitePortUsingTheSchemeDefault(string $baseUrl, string $referer, bool $onSite): void
    {
        config(App::class)->baseURL = $baseUrl;
        service('superglobals')->setServer('HTTP_REFERER', $referer);

        $this->assertRedirectedBackWithExpiredNotice(
            $this->send('POST', '/probe', ['X-Inertia' => 'true']),
            $onSite ? $referer : site_url('/'),
        );
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideInertiaRequestComparesTheRefererPortWithTheSitePortUsingTheSchemeDefault(): iterable
    {
        yield 'http explicit default port' => ['http://example.com/', 'http://example.com:80/settings', true];

        yield 'http one below the default port' => ['http://example.com/', 'http://example.com:79/settings', false];

        yield 'http one above the default port' => ['http://example.com/', 'http://example.com:81/settings', false];

        yield 'https explicit default port' => ['https://example.com/', 'https://example.com:443/settings', true];

        yield 'https one below the default port' => ['https://example.com/', 'https://example.com:442/settings', false];

        yield 'https one above the default port' => ['https://example.com/', 'https://example.com:444/settings', false];

        yield 'https site and http default port' => ['https://example.com/', 'https://example.com:80/settings', false];
    }

    public function testAjaxRequestWithoutTheInertiaHeaderStillThrows(): void
    {
        $this->expectException(SecurityException::class);

        $this->send('POST', '/probe', ['X-Requested-With' => 'XMLHttpRequest'], previousUrl: site_url('/login'));
    }

    public function testPlainFormPostStillThrowsWhenTheSecurityConfigurationAsksForARedirect(): void
    {
        $this->security->redirect = true;
        $this->expectException(SecurityException::class);

        $this->send('POST', '/probe', previousUrl: site_url('/login'));
    }

    public function testSuccessfulGetCarriesAReadableHostOnlyLaxTokenCookieAcceptedByTheTokenCheck(): void
    {
        $cookie = $this->send('GET', '/probe')->response()->getCookie('XSRF-TOKEN');

        $this->assertNotNull($cookie);
        $this->assertFalse($cookie->isHTTPOnly());
        $this->assertSame('Lax', $cookie->getSameSite());
        $this->assertSame('', $cookie->getDomain());
        $this->assertFalse($cookie->isSecure());
        $this->assertNotSame(self::SESSION_HASH, $cookie->getValue());
        $this->send('POST', '/probe', ['X-XSRF-TOKEN' => $cookie->getValue()])->assertStatus(200);
    }

    #[DataProvider('provideTokenCookieLifetimeFollowsTheSessionExpiration')]
    public function testTokenCookieLifetimeFollowsTheSessionExpiration(int $expiration): void
    {
        config(SessionConfig::class)->expiration = $expiration;

        $cookie = $this->send('GET', '/probe')->response()->getCookie('XSRF-TOKEN');

        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(time() + $expiration, $cookie->getExpiresTimestamp(), 5);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideTokenCookieLifetimeFollowsTheSessionExpiration(): iterable
    {
        yield 'two hours' => [7200];

        yield 'one second' => [1];
    }

    public function testTokenCookieIsABrowserSessionCookieWhenTheSessionNeverExpires(): void
    {
        config(SessionConfig::class)->expiration = 0;

        $cookie = $this->send('GET', '/probe')->response()->getCookie('XSRF-TOKEN');

        $this->assertNotNull($cookie);
        $this->assertSame(0, $cookie->getExpiresTimestamp());
    }

    public function testRedirectResponseCarriesTheTokenCookie(): void
    {
        $this->assertNotNull($this->send('GET', '/probe/redirect')->response()->getCookie('XSRF-TOKEN'));
    }

    public function testErrorResponseDoesNotCarryTheTokenCookie(): void
    {
        $response = $this->send('GET', '/probe/forbidden');

        $this->assertSame(403, $response->response()->getStatusCode());
        $this->assertNull($response->response()->getCookie('XSRF-TOKEN'));
    }

    public function testBadRequestResponseDoesNotCarryTheTokenCookie(): void
    {
        $response = $this->send('GET', '/probe/bad-request');

        $this->assertSame(400, $response->response()->getStatusCode());
        $this->assertNull($response->response()->getCookie('XSRF-TOKEN'));
    }

    public function testTokenCookiePathAndSecureFlagFollowTheCookieConfiguration(): void
    {
        config(CookieConfig::class)->path   = '/configured/';
        config(CookieConfig::class)->secure = true;

        $cookie = $this->send('GET', '/probe')->response()->getCookie('XSRF-TOKEN');

        $this->assertNotNull($cookie);
        $this->assertSame('/configured/', $cookie->getPath());
        $this->assertTrue($cookie->isSecure());
    }

    public function testTokenCookieIsSecureWhenTheCookieConfigurationIsSecure(): void
    {
        config(CookieConfig::class)->secure = true;

        $cookie = $this->send('GET', '/probe')->response()->getCookie('XSRF-TOKEN');

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
    }

    public function testTokenCookieIsNotSecureWhenTheCookieConfigurationIsNotSecure(): void
    {
        config(CookieConfig::class)->secure = false;

        $cookie = $this->send('GET', '/probe')->response()->getCookie('XSRF-TOKEN');

        $this->assertNotNull($cookie);
        $this->assertFalse($cookie->isSecure());
    }

    /**
     * Drops the per-request singletons a simulated request leaves behind, so the next
     * simulated request builds its own response, routes, router and security service.
     */
    private function resetPerRequestServices(): void
    {
        BaseService::resetSingle('security');
        BaseService::resetSingle('response');
        BaseService::resetSingle('routes');
        BaseService::resetSingle('router');
    }

    /**
     * Asserts the 303 to the target and the one-shot `error` flash the refusal leaves in the session.
     */
    private function assertRedirectedBackWithExpiredNotice(TestResponse $rejected, ?string $target = null): void
    {
        $rejected->assertStatus(303);
        $rejected->assertRedirectTo($target ?? site_url('/login'));

        $this->assertSame(self::NOTICE, $_SESSION['error']);
        $this->assertSame('new', $_SESSION['__ci_vars']['error']);
    }

    /**
     * Requests a page in a session holding the given token and returns the masked token from the cookie it sets.
     */
    private function issuedToken(string $sessionHash): string
    {
        $cookie = $this->send('GET', '/probe', sessionHash: $sessionHash)->response()->getCookie('XSRF-TOKEN');
        $this->assertNotNull($cookie, 'A successful response must carry the XSRF-TOKEN cookie.');

        return $cookie->getValue();
    }

    /**
     * Registers the probe routes behind both filters: one for every verb, a redirect and a forbidden response.
     */
    private function registerRoutes(): void
    {
        $routes  = $this->withRoutes()->routes;
        $options = ['filter' => ['csrf', 'xsrfcook']];

        foreach (['get', 'post', 'put', 'patch', 'delete'] as $verb) {
            $routes->{$verb}('probe', static fn (): string => 'ok', $options);
        }

        $routes->get('probe/redirect', static fn (): ResponseInterface => redirect()->to('/probe')->setStatusCode(303), $options);
        $routes->get('probe/bad-request', static fn (): ResponseInterface => service('response')->setStatusCode(400), $options);
        $routes->get('probe/forbidden', static fn (): ResponseInterface => service('response')->setStatusCode(403), $options);
    }

    /**
     * Sends one request inside a session that already holds the given session token.
     *
     * @param array<string, string> $headers
     */
    private function send(
        string $method,
        string $path,
        array $headers = [],
        string $sessionHash = self::SESSION_HASH,
        ?string $previousUrl = null,
    ): TestResponse {
        $this->resetPerRequestServices();
        $this->registerRoutes();

        $session = ['csrf_test_name' => $sessionHash];

        if ($previousUrl !== null) {
            $session['_ci_previous_url'] = $previousUrl;
        }

        return $this->withSession($session)->withHeaders($headers)->call($method, $path);
    }
}
