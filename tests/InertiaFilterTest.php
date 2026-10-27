<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use Closure;
use CodeIgniter\Config\BaseService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCURLRequest;
use CodeIgniter\Test\TestResponse;
use Config\App as AppConfig;
use Ejklock\Ci4Inertia\AlwaysProp;
use Ejklock\Ci4Inertia\Config\Inertia as InertiaConfig;
use Ejklock\Ci4Inertia\Filters\InertiaFilter;
use Ejklock\Ci4Inertia\ResponseFactory;
use Ejklock\Ci4Inertia\Ssr\BundleDetector;
use Ejklock\Ci4Inertia\Ssr\Gateway;
use Ejklock\Ci4Inertia\Ssr\HttpGateway;
use Ejklock\Ci4Inertia\Ssr\Response;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * Verifies `Ejklock\Ci4Inertia\Filters\InertiaFilter` against the stock app: the `Vary` header
 * on every response, the stale-version `409` reload with a reflashed session, the
 * `302`-to-`303` redirect conversion scoped to `PUT`/`PATCH`/`DELETE`, and the
 * always-present `errors` shared prop.
 *
 * @internal
 */
final class InertiaFilterTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const CURRENT_VERSION = 'inertia-filter-test-version';

    public static int $hookCalls = 0;
    private Closure $originalVersion;
    private string $originalRootView;
    private bool $sessionGlobalExisted = false;

    /**
     * @var array<string, mixed>
     */
    private array $sessionGlobalBefore = [];

    /**
     * @var array<string, mixed>
     */
    private array $serverBefore = [];

    /**
     * @var array<string, string>
     */
    private array $originalAliases;

    private int $ssrRequests = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $inertia                = config('Inertia');
        $this->originalVersion  = $inertia->version;
        $this->originalRootView = $inertia->rootView;
        $inertia->version       = static fn (): string => self::CURRENT_VERSION;
        $inertia->rootView      = 'Ejklock\Ci4InertiaTestSupport\Views\shell';

        $filters                     = config('Filters');
        $this->originalAliases       = $filters->aliases;
        $filters->aliases['inertia'] = InertiaFilter::class;

        $this->sessionGlobalExisted = isset($_SESSION);
        $this->sessionGlobalBefore  = $_SESSION ?? [];
        $this->serverBefore         = service('superglobals')->getServerArray();

        $this->resetPerRequestServices();
    }

    protected function tearDown(): void
    {
        $inertia                   = config('Inertia');
        $inertia->version          = $this->originalVersion;
        $inertia->rootView         = $this->originalRootView;
        $inertia->storePreviousUrl = true;
        $superglobals              = service('superglobals');
        $superglobals->setCookieArray([]);
        $superglobals->setServerArray($this->serverBefore);
        BaseService::resetSingle('superglobals');
        BaseService::resetSingle('request');

        if ($this->sessionGlobalExisted) {
            $_SESSION = $this->sessionGlobalBefore;
        } else {
            unset($_SESSION);
        }

        config('Filters')->aliases = $this->originalAliases;

        $this->resetPerRequestServices();
        BaseService::resetSingle('session');
        BaseService::resetSingle('inertiaSsrGateway');

        parent::tearDown();
    }

    /**
     * Drops the per-request singletons a simulated request leaves behind, so the next
     * simulated request builds its own response, routes, router and `inertia` service.
     */
    private function resetPerRequestServices(): void
    {
        BaseService::resetSingle('response');
        BaseService::resetSingle('redirectresponse');
        BaseService::resetSingle('inertia');
        BaseService::resetSingle('routes');
        BaseService::resetSingle('router');
    }

    /**
     * A `GET /page` route behind the `inertia` filter that renders the `Page/Index`
     * component with an `items` prop.
     *
     * @return array{0: string, 1: string, 2: Closure, 3: array{filter: string}}
     */
    private function renderingRoute(): array
    {
        return ['GET', 'page', static fn () => service('inertia')
            ->render('Page/Index', ['items' => []])
            ->toResponse(service('request'), service('response')), ['filter' => 'inertia']];
    }

    public function testNonInertiaResponseCarriesVaryHeader(): void
    {
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->get('/page');

        $result->assertStatus(200);
        $result->assertHeader('Vary', 'X-Inertia');
    }

    public function testInertiaResponseWithFreshVersionCarriesVaryHeader(): void
    {
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->get('/page');

        $result->assertStatus(200);
        $result->assertHeader('Vary', 'X-Inertia');
    }

    #[DataProvider('provideInertiaGetVersionComparisonBoundary')]
    public function testInertiaGetVersionComparisonBoundary(?string $requestVersion, int $expectedStatus): void
    {
        $this->withRoutes([$this->renderingRoute()]);

        $headers = ['X-Inertia' => 'true'];

        if ($requestVersion !== null) {
            $headers['X-Inertia-Version'] = $requestVersion;
        }

        $result = $this->withHeaders($headers)->get('/page');

        $result->assertStatus($expectedStatus);
    }

    /**
     * @return iterable<string, array{0: string|null, 1: int}>
     */
    public static function provideInertiaGetVersionComparisonBoundary(): iterable
    {
        yield 'matching version is fresh' => [self::CURRENT_VERSION, 200];

        yield 'differing version is stale' => ['a-different-version', 409];

        yield 'absent version is treated as stale' => [null, 409];

        yield 'empty version is treated as stale' => ['', 409];

        yield 'version with a trailing character is stale' => [self::CURRENT_VERSION . 'x', 409];

        yield 'version missing its last character is stale' => [substr(self::CURRENT_VERSION, 0, -1), 409];
    }

    public function testStaleVersionResponseCarriesLocationAndVersionHeaders(): void
    {
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'stale-version'])
            ->get('/page');

        $result->assertStatus(409);
        $result->assertHeader('X-Inertia-Location', '/page');
        $result->assertHeader('X-Inertia-Version', self::CURRENT_VERSION);
        $result->assertHeader('Vary', 'X-Inertia');
    }

    public function testStaleVersionLocationEchoesTheRequestsOwnQueryString(): void
    {
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'stale-version'])
            ->get('/page?filter=open');

        $result->assertStatus(409);
        $result->assertHeader('X-Inertia-Location', '/page?filter=open');
    }

    public function testStaleVersionReflashesSessionFlashDataSoItSurvivesTheNextRequest(): void
    {
        $this->withRoutes([$this->renderingRoute()]);

        $session = [
            'errors'    => ['subject' => 'Subject is required.'],
            '__ci_vars' => ['errors' => 'old'],
        ];

        $result = $this->withSession($session)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'stale-version'])
            ->get('/page');

        $result->assertStatus(409);
        $this->assertSame('new', $_SESSION['__ci_vars']['errors']);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionWithInertiaFlashData(): array
    {
        return [
            'inertia.flash_data' => ['toast' => 'Saved'],
            '__ci_vars'          => ['inertia.flash_data' => 'old'],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('provideARedirectFlashesTheInertiaFlashDataAgainSoItSurvivesOneMoreRequest')]
    public function testARedirectFlashesTheInertiaFlashDataAgainSoItSurvivesOneMoreRequest(array $headers): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => redirect()->to('/other'), ['filter' => 'inertia']],
        ]);

        $result = $this->withSession($this->sessionWithInertiaFlashData())->withHeaders($headers)->post('page');

        $result->assertRedirect();
        $this->assertSame(['toast' => 'Saved'], $_SESSION['inertia.flash_data']);
        $this->assertSame('new', $_SESSION['__ci_vars']['inertia.flash_data']);
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function provideARedirectFlashesTheInertiaFlashDataAgainSoItSurvivesOneMoreRequest(): iterable
    {
        yield 'inertia request' => [['X-Inertia' => 'true']];

        yield 'plain request' => [[]];
    }

    public function testFlashSetBeforeARedirectReachesTheNextPageOfARequestCarryingTheSessionWithoutACookie(): void
    {
        $this->withRoutes([
            ['POST', 'save', static function () {
                service('inertia')->flash('toast', 'Saved');

                return redirect()->to('/page');
            }, ['filter' => 'inertia']],
            $this->renderingRoute(),
        ]);

        $this->withSession([])->withHeaders(['X-Inertia' => 'true'])->post('save');
        $carried = $_SESSION;
        $this->resetPerRequestServices();
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withSession($carried)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->get('/page');

        $this->assertSame(['toast' => 'Saved'], json_decode($result->getJSON(), true)['flash']);
    }

    public function testPreserveFragmentSetBeforeARedirectReachesTheNextPageOfARequestCarryingTheSessionWithoutACookie(): void
    {
        $this->withRoutes([
            ['POST', 'save', static function () {
                service('inertia')->preserveFragment();

                return redirect()->to('/page');
            }, ['filter' => 'inertia']],
            $this->renderingRoute(),
        ]);

        $this->withSession([])->withHeaders(['X-Inertia' => 'true'])->post('save');
        $carried = $_SESSION;
        $this->resetPerRequestServices();
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withSession($carried)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->get('/page');

        $this->assertTrue(json_decode($result->getJSON(), true)['preserveFragment']);
    }

    public function testARedirectWithoutInertiaFlashDataWritesNothing(): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => redirect()->to('/other'), ['filter' => 'inertia']],
        ]);

        $this->withSession(['other' => 'kept'])->withHeaders(['X-Inertia' => 'true'])->post('page');

        $this->assertArrayNotHasKey('inertia.flash_data', $_SESSION ?? []);
        $this->assertArrayNotHasKey('__ci_vars', $_SESSION ?? []);
    }

    public function testARedirectWithEmptyInertiaFlashDataFlashesNothingAgain(): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => redirect()->to('/other'), ['filter' => 'inertia']],
        ]);

        $session = ['inertia.flash_data' => [], '__ci_vars' => ['inertia.flash_data' => 'old']];

        $this->withSession($session)->withHeaders(['X-Inertia' => 'true'])->post('page');

        $this->assertSame('old', $_SESSION['__ci_vars']['inertia.flash_data']);
    }

    public function testAResponseThatIsNotARedirectLeavesTheInertiaFlashDataAlone(): void
    {
        $this->withRoutes([['GET', 'plain', static fn () => service('response')->setBody('ok'), ['filter' => 'inertia']]]);

        $this->withSession($this->sessionWithInertiaFlashData())
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->get('/plain');

        $this->assertSame('old', $_SESSION['__ci_vars']['inertia.flash_data']);
    }

    public function testARedirectForARequestWithoutASessionStartsNone(): void
    {
        unset($_SESSION);
        BaseService::resetSingle('session');
        BaseService::resetSingle('request');

        $request  = service('request')->setHeader('X-Inertia', 'true');
        $response = service('response')->setStatusCode(302)->setHeader('Location', '/other');

        (new InertiaFilter())->after($request, $response);

        $this->assertFalse(BaseService::has('session'), 'The session service was constructed.');
        $this->assertFalse(isset($_SESSION));
    }

    #[DataProvider('provideDestructiveMethodRedirectIsConvertedFrom302To303')]
    public function testInertiaDestructiveMethodRedirectIsConvertedFrom302To303(string $method): void
    {
        $this->withRoutes([
            [$method, 'page/1', static fn () => redirect()->to('/page/1', 302), ['filter' => 'inertia']],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->call($method, 'page/1');

        $result->assertStatus(303);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideDestructiveMethodRedirectIsConvertedFrom302To303(): iterable
    {
        yield 'PUT' => ['PUT'];

        yield 'PATCH' => ['PATCH'];

        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('provideDestructiveMethodRedirectIsConvertedFrom302To303')]
    public function testNonInertiaDestructiveMethodRedirectStaysAt302(string $method): void
    {
        $this->withRoutes([
            [$method, 'page/1', static fn () => redirect()->to('/page/1', 302), ['filter' => 'inertia']],
        ]);

        $this->call($method, 'page/1')->assertStatus(302);
    }

    public function testInertiaPostRedirectStaysAt302(): void
    {
        $this->withRoutes([
            ['POST', 'page/1', static fn () => redirect()->to('/page/1', 302), ['filter' => 'inertia']],
        ]);

        $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('page/1')->assertStatus(302);
    }

    #[DataProvider('provideInertiaPutRedirectOtherThan302IsUnchanged')]
    public function testInertiaPutRedirectOtherThan302IsUnchanged(int $status): void
    {
        $this->withRoutes([
            ['PUT', 'page/1', static fn () => redirect()->to('/page/1', $status), ['filter' => 'inertia']],
        ]);

        $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->call('PUT', 'page/1')->assertStatus($status);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function provideInertiaPutRedirectOtherThan302IsUnchanged(): iterable
    {
        yield '301' => [301];

        yield '307' => [307];
    }

    public function testInertiaGetReturningAnEmptyBodyRedirectsBackToTheReferer(): void
    {
        $this->withRoutes([
            ['GET', 'page', static fn () => service('response')->setBody(''), ['filter' => 'inertia']],
        ]);

        config('Inertia')->storePreviousUrl = false;
        service('superglobals')->setServer('HTTP_REFERER', 'http://localhost/users');

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => self::CURRENT_VERSION])->get('/page');

        $this->assertStringEndsWith('/users', $result->response()->getHeaderLine('Location'));
        $this->assertSame('X-Inertia', $result->response()->getHeaderLine('Vary'));
    }

    public function testInertiaPutReturningAnEmptyBodyRedirectsBackWith303(): void
    {
        $this->withRoutes([
            ['PUT', 'page', static fn () => service('response')->setBody(''), ['filter' => 'inertia']],
        ]);

        config('Inertia')->storePreviousUrl = false;
        service('superglobals')->setServer('HTTP_REFERER', 'http://localhost/users');

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->call('PUT', 'page');

        $result->assertStatus(303);
        $this->assertStringEndsWith('/users', $result->response()->getHeaderLine('Location'));
    }

    public function testNonInertiaRequestReturningAnEmptyBodyStays200(): void
    {
        $this->withRoutes([
            ['GET', 'page', static fn () => service('response')->setBody(''), ['filter' => 'inertia']],
        ]);

        $this->get('/page')->assertStatus(200);
    }

    public function testInertiaRequestAnswering204IsUnchanged(): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => service('response')->setStatusCode(204), ['filter' => 'inertia']],
        ]);

        $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('/page')->assertStatus(204);
    }

    public function testInertiaRequestReturningTheBodyZeroIsUnchanged(): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => service('response')->setBody('0'), ['filter' => 'inertia']],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('/page');

        $result->assertStatus(200);
        $this->assertSame('0', (string) $result->response()->getBody());
    }

    public function testAnExtendingFilterOverridesTheEmptyResponseHandling(): void
    {
        $extending = new class () extends InertiaFilter {
            protected function onEmptyResponse(IncomingRequest $request, ResponseInterface $response): ResponseInterface
            {
                return service('response')->setStatusCode(202)->setBody('handled');
            }
        };

        config('Filters')->aliases['inertia'] = $extending::class;
        $this->withRoutes([
            ['POST', 'page', static fn () => service('response')->setBody(''), ['filter' => 'inertia']],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('/page');

        $result->assertStatus(202);
        $this->assertSame('handled', (string) $result->response()->getBody());
    }

    public function testInertiaPostRedirectToAFragmentBecomesA409WithTheRedirectHeader(): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => redirect()->to('/users#top'), ['filter' => 'inertia']],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('/page');

        $result->assertStatus(409);
        $this->assertStringEndsWith('/users#top', $result->response()->getHeaderLine('X-Inertia-Redirect'));
        $this->assertSame('', (string) $result->response()->getBody());
        $this->assertSame('X-Inertia', $result->response()->getHeaderLine('Vary'));
    }

    #[DataProvider('provideEveryRedirectStatusToAFragmentBecomesA409')]
    public function testEveryRedirectStatusToAFragmentBecomesA409(int $status): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => service('response')->setStatusCode($status)->setHeader('Location', '/users#top')->setBody('x'), ['filter' => 'inertia']],
        ]);

        $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('/page')->assertStatus(409);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function provideEveryRedirectStatusToAFragmentBecomesA409(): iterable
    {
        foreach ([201, 301, 302, 303, 307, 308] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('provideANonRedirectStatusCarryingAFragmentLocationIsUnchanged')]
    public function testANonRedirectStatusCarryingAFragmentLocationIsUnchanged(int $status): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => service('response')->setStatusCode($status)->setHeader('Location', '/users#top')->setBody('x'), ['filter' => 'inertia']],
        ]);

        $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('/page')->assertStatus($status);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function provideANonRedirectStatusCarryingAFragmentLocationIsUnchanged(): iterable
    {
        foreach ([200, 202, 300, 304] as $status) {
            yield (string) $status => [$status];
        }
    }

    public function testInertiaGetAnsweredWithNoBodyAtAllRedirectsBack(): void
    {
        $this->withRoutes([
            ['GET', 'page', static fn () => service('response'), ['filter' => 'inertia']],
        ]);

        config('Inertia')->storePreviousUrl = false;
        service('superglobals')->setServer('HTTP_REFERER', 'http://localhost/users');

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => self::CURRENT_VERSION])->get('/page');

        $this->assertStringEndsWith('/users', $result->response()->getHeaderLine('Location'));
    }

    public function testShouldStoreCurrentUrlIsCallableByAnApplication(): void
    {
        $request = $this->inertiaGetRequest();
        $request->setGlobal('cookie', [(string) config('Session')->cookieName => 'abc']);

        $this->assertTrue((new InertiaFilter())->shouldStoreCurrentUrl($request, $this->renderedPageResponse()));
    }

    public function testInertiaPutRedirectToAFragmentBecomes409AfterThe303Rewrite(): void
    {
        $this->withRoutes([
            ['PUT', 'page', static fn () => redirect()->to('/a#b', 302), ['filter' => 'inertia']],
        ]);

        $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->call('PUT', 'page')->assertStatus(409);
    }

    public function testInertiaRedirectWithoutAFragmentIsUnchanged(): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => redirect()->to('/users'), ['filter' => 'inertia']],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('/page');

        $result->assertStatus(302);
        $this->assertFalse($result->response()->hasHeader('X-Inertia-Redirect'));
    }

    public function testNonInertiaRedirectToAFragmentIsUnchanged(): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => redirect()->to('/users#top'), ['filter' => 'inertia']],
        ]);

        $result = $this->post('/page');

        $result->assertStatus(302);
        $this->assertFalse($result->response()->hasHeader('X-Inertia-Redirect'));
    }

    #[DataProvider('providePrefetchRedirectToAFragmentIsUnchanged')]
    public function testPrefetchRedirectToAFragmentIsUnchanged(string $header): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => redirect()->to('/users#top'), ['filter' => 'inertia']],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', $header => 'prefetch'])->post('/page');

        $result->assertStatus(302);
        $this->assertFalse($result->response()->hasHeader('X-Inertia-Redirect'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function providePrefetchRedirectToAFragmentIsUnchanged(): iterable
    {
        yield 'Purpose' => ['Purpose'];

        yield 'Sec-Purpose' => ['Sec-Purpose'];

        yield 'X-Moz' => ['X-Moz'];
    }

    public function testAnExtendingFilterOverridesTheFragmentRedirectHandling(): void
    {
        $extending = new class () extends InertiaFilter {
            protected function onRedirectWithFragment(IncomingRequest $request, ResponseInterface $response): ResponseInterface
            {
                return service('response')->setStatusCode(418);
            }
        };

        config('Filters')->aliases['inertia'] = $extending::class;
        $this->withRoutes([
            ['POST', 'page', static fn () => redirect()->to('/users#top'), ['filter' => 'inertia']],
        ]);

        $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('/page')->assertStatus(418);
    }

    /**
     * Visits `$url` as an Inertia GET carrying `$headers` with a started session that
     * already holds an older previous URL, then asks a `POST` route for `redirect()->back()`
     * and returns where it points.
     *
     * @param array<string, string> $headers
     */
    private function backLocationAfterAnInertiaGet(string $url, array $headers = []): string
    {
        $this->withRoutes([
            ['GET', 'users', static fn () => service('inertia')
                ->render('Page/Index', [])
                ->toResponse(service('request'), service('response')), ['filter' => 'inertia']],
        ]);

        $this->withSession(['_ci_previous_url' => 'http://localhost/older'])
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => self::CURRENT_VERSION] + $headers)
            ->get($url)
            ->assertStatus(200);

        $this->resetPerRequestServices();
        $this->withRoutes([
            ['POST', 'back', static fn () => redirect()->back(), ['filter' => 'inertia']],
        ]);

        return $this->withSession($_SESSION)->post('/back')->response()->getHeaderLine('Location');
    }

    public function testInertiaGetRecordsItselfAsThePreviousUrl(): void
    {
        $this->assertStringEndsWith('/users?page=2', $this->backLocationAfterAnInertiaGet('/users?page=2'));
    }

    #[DataProvider('provideAnInertiaNonGetRenderingAPageDoesNotRecordItselfAsThePreviousUrl')]
    public function testAnInertiaNonGetRenderingAPageDoesNotRecordItselfAsThePreviousUrl(string $method): void
    {
        $this->withRoutes([
            [$method, 'form', static fn () => service('inertia')
                ->render('Page/Index', [])
                ->toResponse(service('request'), service('response')), ['filter' => 'inertia']],
        ]);

        $this->withSession(['_ci_previous_url' => 'http://localhost/older'])
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->call($method, '/form')
            ->assertStatus(200);

        $this->assertSame('http://localhost/older', $_SESSION['_ci_previous_url']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideAnInertiaNonGetRenderingAPageDoesNotRecordItselfAsThePreviousUrl(): iterable
    {
        yield 'POST' => ['POST'];

        yield 'PUT' => ['PUT'];
    }

    #[DataProvider('provideAnInertiaGetRenderingAPageWithAnotherStatusIsNotRecorded')]
    public function testAnInertiaGetRenderingAPageWithAnotherStatusIsNotRecorded(int $status): void
    {
        $this->withRoutes([
            ['GET', 'users', static fn () => service('inertia')
                ->render('Page/Index', [])
                ->toResponse(service('request'), service('response'))
                ->setStatusCode($status), ['filter' => 'inertia']],
        ]);

        $this->withSession(['_ci_previous_url' => 'http://localhost/older'])
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->get('/users')
            ->assertStatus($status);

        $this->assertSame('http://localhost/older', $_SESSION['_ci_previous_url']);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function provideAnInertiaGetRenderingAPageWithAnotherStatusIsNotRecorded(): iterable
    {
        yield '422' => [422];

        yield '201' => [201];
    }

    public function testAnEmptyInertiaPutWithoutASessionGoesBackToTheRefererWithoutStartingOne(): void
    {
        unset($_SESSION);
        BaseService::resetSingle('session');
        service('superglobals')->setServer('HTTP_REFERER', 'http://localhost/form');
        BaseService::resetSingle('request');

        $request = service('request')->withMethod('PUT')->setHeader('X-Inertia', 'true');

        $response = (new InertiaFilter())->after($request, service('response')->setBody(''));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertStringEndsWith('/form', $response->getHeaderLine('Location'));
        $this->assertFalse(BaseService::has('session'), 'The session service was constructed.');
        $this->assertFalse(isset($_SESSION));
    }

    #[DataProvider('provideAnEmptyInertiaResponseRedirectsWithTheStatusTheProtocolRequiresOverHttp11')]
    public function testAnEmptyInertiaResponseRedirectsWithTheStatusTheProtocolRequiresOverHttp11(string $method, int $status): void
    {
        $superglobals = service('superglobals');
        $superglobals->setServer('SERVER_PROTOCOL', 'HTTP/1.1');
        $superglobals->setServer('REQUEST_METHOD', $method);
        BaseService::resetSingle('request');
        BaseService::resetSingle('response');
        BaseService::resetSingle('redirectresponse');

        $request = service('request')->withMethod($method)->setHeader('X-Inertia', 'true');

        $response = (new InertiaFilter())->after($request, service('response')->setBody(''));

        $this->assertSame($status, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function provideAnEmptyInertiaResponseRedirectsWithTheStatusTheProtocolRequiresOverHttp11(): iterable
    {
        yield 'PATCH' => ['PATCH', 303];

        yield 'PUT' => ['PUT', 303];

        yield 'DELETE' => ['DELETE', 303];

        yield 'GET' => ['GET', 302];

        yield 'POST' => ['POST', 302];
    }

    public function testTheFragmentRedirectDropsABodyTheControllerWrote(): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => service('response')->setBody('written before the redirect')->redirect('/users#top'), ['filter' => 'inertia']],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('/page');

        $result->assertStatus(409);
        $this->assertSame('', (string) $result->response()->getBody());
        $this->assertFalse($result->response()->hasHeader('Location'));
    }

    public function testAnEmptyInertiaPutWithALiveSessionGoesBackToTheStoredUrl(): void
    {
        $this->withRoutes([
            ['PUT', 'page', static fn () => service('response')->setBody(''), ['filter' => 'inertia']],
        ]);

        $result = $this->withSession(['_ci_previous_url' => 'http://localhost/list'])
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->call('PUT', 'page');

        $result->assertStatus(303);
        $this->assertSame('http://localhost/list', $result->response()->getHeaderLine('Location'));
    }

    public function testAnEmptyInertiaGetWithNoRefererGoesBackToTheSiteBaseUrl(): void
    {
        $this->withRoutes([
            ['GET', 'page', static fn () => service('response')->setBody(''), ['filter' => 'inertia']],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->get('/page');

        $result->assertStatus(302);
        $this->assertSame(site_url('/'), $result->response()->getHeaderLine('Location'));
    }

    public function testTheFragmentRedirectCarriesNoLocationHeaderEvenWhenTheControllerRedirectedTheSharedResponse(): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => service('response')->redirect('/users#top'), ['filter' => 'inertia']],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('/page');

        $result->assertStatus(409);
        $this->assertSame('/users#top', $result->response()->getHeaderLine('X-Inertia-Redirect'));
        $this->assertFalse($result->response()->hasHeader('Location'));
    }

    public function testTheFragmentRedirectKeepsTheCookiesAlreadyOnTheResponse(): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => service('response')->setCookie('visited', 'yes')->redirect('/users#top'), ['filter' => 'inertia']],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('/page');

        $result->assertStatus(409);
        $this->assertTrue($result->response()->hasCookie('visited'));
    }

    public function testTheFragmentRedirectOfARedirectResponseCarriesNoLocationHeader(): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => redirect()->to('/users#top'), ['filter' => 'inertia']],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('/page');

        $result->assertStatus(409);
        $this->assertFalse($result->response()->hasHeader('Location'));
    }

    public function testARedirectWithoutAFragmentKeepsItsLocation(): void
    {
        $this->withRoutes([
            ['POST', 'page', static fn () => redirect()->to('/users'), ['filter' => 'inertia']],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->post('/page');

        $result->assertStatus(302);
        $this->assertStringEndsWith('/users', $result->response()->getHeaderLine('Location'));
    }

    public function testPartialReloadOfTheRenderedComponentIsNotRecorded(): void
    {
        $location = $this->backLocationAfterAnInertiaGet('/users?page=2', ['X-Inertia-Partial-Component' => 'Page/Index']);

        $this->assertStringEndsWith('/older', $location);
    }

    public function testPartialReloadNamingAnotherComponentIsRecorded(): void
    {
        $location = $this->backLocationAfterAnInertiaGet('/users?page=2', ['X-Inertia-Partial-Component' => 'Other/Page']);

        $this->assertStringEndsWith('/users?page=2', $location);
    }

    #[DataProvider('provideRequestsThatAreNotNavigationsAreNotRecorded')]
    public function testRequestsThatAreNotNavigationsAreNotRecorded(string $header, string $value): void
    {
        $location = $this->backLocationAfterAnInertiaGet('/users', [$header => $value]);

        $this->assertStringEndsWith('/older', $location);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideRequestsThatAreNotNavigationsAreNotRecorded(): iterable
    {
        yield 'Purpose prefetch' => ['Purpose', 'prefetch'];

        yield 'Sec-Purpose prefetch' => ['Sec-Purpose', 'prefetch'];

        yield 'X-Moz prefetch' => ['X-Moz', 'prefetch'];

        yield 'X-Moz prefetch in capitals' => ['X-Moz', 'PREFETCH'];

        yield 'Precognition' => ['Precognition', 'true'];
    }

    public function testAnEmptyInertiaGetIsNotRecordedSoItsBackRedirectDoesNotPointAtItself(): void
    {
        $this->withRoutes([
            ['GET', 'page', static fn () => service('response')->setBody(''), ['filter' => 'inertia']],
        ]);

        $result = $this->withSession(['_ci_previous_url' => 'http://localhost/older'])
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->get('/page');

        $this->assertSame('http://localhost/older', $_SESSION['_ci_previous_url']);
        $this->assertStringEndsWith('/older', $result->response()->getHeaderLine('Location'));
    }

    public function testAnInertiaGetAnsweredWithARedirectIsNotRecorded(): void
    {
        $this->withRoutes([
            ['GET', 'page', static fn () => redirect()->to('/elsewhere'), ['filter' => 'inertia']],
        ]);

        $this->withSession(['_ci_previous_url' => 'http://localhost/older'])
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->get('/page');

        $this->assertSame('http://localhost/older', $_SESSION['_ci_previous_url']);
    }

    public function testAStaleVersionVisitIsNotRecorded(): void
    {
        $this->withRoutes([$this->renderingRoute()]);

        $this->withSession(['_ci_previous_url' => 'http://localhost/older'])
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => 'stale'])
            ->get('/page')
            ->assertStatus(409);

        $this->assertSame('http://localhost/older', $_SESSION['_ci_previous_url']);
    }

    public function testNothingIsRecordedWhenStorePreviousUrlIsOff(): void
    {
        config('Inertia')->storePreviousUrl = false;

        $this->assertStringEndsWith('/older', $this->backLocationAfterAnInertiaGet('/users'));
    }

    private function renderedPageResponse(): ResponseInterface
    {
        return service('response')->setBody('{}')->setHeader('X-Inertia', 'true');
    }

    private function inertiaGetRequest(): IncomingRequest
    {
        return service('request')->withMethod('GET')->setHeader('X-Inertia', 'true');
    }

    public function testInertiaGetWithoutASessionCookieOrStartedSessionNeverStartsOne(): void
    {
        unset($_SESSION);
        BaseService::injectMock('session', new class () {
            public function __call(string $name, array $arguments): never
            {
                Assert::fail("The session service was used through {$name}().");
            }
        });

        $response = (new InertiaFilter())->after($this->inertiaGetRequest(), $this->renderedPageResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse(isset($_SESSION));
    }

    public function testInertiaGetCarryingTheSessionCookieIsRecordedEvenBeforeTheSessionStarted(): void
    {
        unset($_SESSION);
        $request = $this->inertiaGetRequest();
        $request->setGlobal('cookie', [(string) config('Session')->cookieName => 'abc']);

        (new InertiaFilter())->after($request, $this->renderedPageResponse());

        $this->assertSame((string) $request->getUri(), $_SESSION['_ci_previous_url']);
    }

    public function testNonInertiaGetIsLeftToCodeIgniter(): void
    {
        $this->withRoutes([$this->renderingRoute()]);

        $this->withSession(['_ci_previous_url' => 'http://localhost/older'])->get('/page');

        $this->assertStringEndsWith('/page', $_SESSION['_ci_previous_url']);
    }

    public function testAnExtendingFilterSharesItsOwnPropsNextToErrors(): void
    {
        $extending = new class () extends InertiaFilter {
            protected function share(IncomingRequest $request): array
            {
                return array_merge(parent::share($request), [
                    'locale' => new AlwaysProp(static fn (): string => 'pt-BR'),
                ]);
            }
        };

        config('Filters')->aliases['inertia'] = $extending::class;

        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->get('/page');

        $result->assertStatus(200);

        $page = json_decode((string) $result->response()->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('pt-BR', $page['props']['locale']);
        $this->assertSame([], $page['props']['errors']);
    }

    /**
     * Registers an extending filter whose `version()` hook returns the request's
     * `X-Build` header when it carries one, else `from-hook`, and counts its own calls.
     */
    private function useVersionHookFilter(): void
    {
        self::$hookCalls = 0;

        $extending = new class () extends InertiaFilter {
            protected function version(IncomingRequest $request): string
            {
                InertiaFilterTest::$hookCalls++;

                return $request->getHeaderLine('X-Build') ?: 'from-hook';
            }
        };

        config('Filters')->aliases['inertia'] = $extending::class;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageFrom(TestResponse $result): array
    {
        return json_decode((string) $result->response()->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testAnInertiaGetCarryingTheHookVersionRendersThatVersion(): void
    {
        $this->useVersionHookFilter();
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'from-hook'])->get('/page');

        $result->assertStatus(200);
        $this->assertSame('from-hook', $this->pageFrom($result)['version']);
    }

    public function testAnInertiaGetCarryingTheConfigVersionIsStaleAgainstTheHookVersion(): void
    {
        $this->useVersionHookFilter();
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => self::CURRENT_VERSION])->get('/page');

        $result->assertStatus(409);
        $result->assertHeader('X-Inertia-Version', 'from-hook');
    }

    public function testAFullPageLoadEmbedsTheHookVersionInTheRootView(): void
    {
        $this->useVersionHookFilter();
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->get('/page');

        $result->assertStatus(200);
        $this->assertSame(1, preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', (string) $result->response()->getBody(), $matches));

        $page = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('from-hook', $page['version']);
    }

    public function testAnInertiaPostRendersTheHookVersion(): void
    {
        $this->useVersionHookFilter();
        $this->withRoutes([
            ['POST', 'page', static fn () => service('inertia')
                ->render('Page/Index', [])
                ->toResponse(service('request'), service('response')), ['filter' => 'inertia']],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true'])->post('/page');

        $result->assertStatus(200);
        $this->assertSame('from-hook', $this->pageFrom($result)['version']);
    }

    public function testTheHookReceivesTheCurrentRequest(): void
    {
        $this->useVersionHookFilter();
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'build-42', 'X-Build' => 'build-42'])
            ->get('/page');

        $result->assertStatus(200);
        $this->assertSame('build-42', $this->pageFrom($result)['version']);
    }

    public function testTheHookRunsOnceOnAnInertiaGetThatRenders(): void
    {
        $this->useVersionHookFilter();
        $this->withRoutes([$this->renderingRoute()]);

        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'from-hook'])->get('/page')->assertStatus(200);

        $this->assertSame(1, self::$hookCalls);
    }

    public function testTheHookRunsOnceOnAStaleVersionResponse(): void
    {
        $this->useVersionHookFilter();
        $this->withRoutes([$this->renderingRoute()]);

        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'stale'])->get('/page')->assertStatus(409);

        $this->assertSame(1, self::$hookCalls);
    }

    public function testTheHookDoesNotRunOnAnInertiaPostThatRedirectsWithoutRendering(): void
    {
        $this->useVersionHookFilter();
        $this->withRoutes([
            ['POST', 'page', static fn () => redirect()->to('/'), ['filter' => 'inertia']],
        ]);

        $this->withHeaders(['X-Inertia' => 'true'])->post('/page');

        $this->assertSame(0, self::$hookCalls);
    }

    public function testTheHookDoesNotRunOnANonInertiaGetThatReturnsAPlainString(): void
    {
        $this->useVersionHookFilter();
        $this->withRoutes([
            ['GET', 'page', static fn () => 'plain', ['filter' => 'inertia']],
        ]);

        $this->get('/page')->assertStatus(200);

        $this->assertSame(0, self::$hookCalls);
    }

    public function testTheHookRunsOnceOnAFullPageLoadThatRenders(): void
    {
        $this->useVersionHookFilter();
        $this->withRoutes([$this->renderingRoute()]);

        $this->get('/page')->assertStatus(200);

        $this->assertSame(1, self::$hookCalls);
    }

    public function testErrorsPropIsAnEmptyObjectWhenSessionHasNoValidationErrors(): void
    {
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->get('/page');

        $result->assertStatus(200);

        $page = json_decode((string) $result->response()->getBody(), flags: JSON_THROW_ON_ERROR);

        $this->assertInstanceOf(stdClass::class, $page->props->errors);
        $this->assertSame([], get_object_vars($page->props->errors));
    }

    public function testErrorsPropCarriesSessionValidationErrorsWhenPresent(): void
    {
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withSession(['errors' => ['subject' => 'Subject is required.']])
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->get('/page');

        $result->assertStatus(200);

        $page = json_decode((string) $result->response()->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['subject' => 'Subject is required.'], $page['props']['errors']);
    }

    public function testErrorsPropIsForceIncludedOnAPartialReloadThatDoesNotRequestIt(): void
    {
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withSession(['errors' => ['subject' => 'Subject is required.']])
            ->withHeaders([
                'X-Inertia'                   => 'true',
                'X-Inertia-Version'           => self::CURRENT_VERSION,
                'X-Inertia-Partial-Component' => 'Page/Index',
                'X-Inertia-Partial-Data'      => 'items',
            ])
            ->get('/page');

        $result->assertStatus(200);

        $page = json_decode((string) $result->response()->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('items', $page['props']);
        $this->assertSame(['subject' => 'Subject is required.'], $page['props']['errors']);
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $expectedErrors
     */
    #[DataProvider('provideErrorsPropFollowsTheSessionErrorSourcePrecedence')]
    public function testErrorsPropFollowsTheSessionErrorSourcePrecedence(array $session, array $expectedErrors): void
    {
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withSession($session)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->get('/page');

        $result->assertStatus(200);

        $page = json_decode((string) $result->response()->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($expectedErrors, $page['props']['errors']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function provideErrorsPropFollowsTheSessionErrorSourcePrecedence(): iterable
    {
        yield 'only the errors flash' => [
            ['errors' => ['subject' => 'Subject is required.', 'body' => 'Body is required.']],
            ['subject' => 'Subject is required.', 'body' => 'Body is required.'],
        ];

        yield 'only the withInput validation errors' => [
            ['_ci_validation_errors' => ['email' => 'Email is invalid.']],
            ['email' => 'Email is invalid.'],
        ];

        yield 'both sources: the errors flash wins whole' => [
            [
                'errors'                => ['subject' => 'Subject is required.', 'body' => 'Body is required.'],
                '_ci_validation_errors' => ['email' => 'Email is invalid.'],
            ],
            ['subject' => 'Subject is required.', 'body' => 'Body is required.'],
        ];

        yield 'a non-array errors flash falls through to the validation errors' => [
            [
                'errors'                => 'something went wrong',
                '_ci_validation_errors' => ['email' => 'Email is invalid.'],
            ],
            ['email' => 'Email is invalid.'],
        ];

        yield 'a non-array validation errors value alone is no errors' => [
            ['_ci_validation_errors' => 'not an array'],
            [],
        ];

        yield 'neither source' => [[], []];
    }

    /**
     * @param array<string, mixed>  $session
     * @param array<string, string> $headers
     */
    #[DataProvider('provideTheErrorsPropShape')]
    public function testTheErrorsPropShape(bool $withAllErrors, array $session, array $headers, string $expectedJson): void
    {
        if ($withAllErrors) {
            config('Filters')->aliases['inertia'] = (new class () extends InertiaFilter {
                protected bool $withAllErrors = true;
            })::class;
        }

        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withSession($session)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => self::CURRENT_VERSION] + $headers)
            ->get('/page');

        $result->assertStatus(200);

        $page = json_decode((string) $result->response()->getBody(), flags: JSON_THROW_ON_ERROR);

        $this->assertSame($expectedJson, json_encode($page->props->errors, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{0: bool, 1: array<string, mixed>, 2: array<string, string>, 3: string}>
     */
    public static function provideTheErrorsPropShape(): iterable
    {
        $bag = ['X-Inertia-Error-Bag' => 'createUser'];

        yield 'a string message stays a string' => [false, ['errors' => ['subject' => 'Required.']], [], '{"subject":"Required."}'];

        yield 'a list keeps only its first message' => [false, ['errors' => ['subject' => ['Required.', 'Too short.']]], [], '{"subject":"Required."}'];

        yield 'every message as a list when all errors are on' => [true, ['errors' => ['subject' => ['Required.', 'Too short.']]], [], '{"subject":["Required.","Too short."]}'];

        yield 'a string message becomes a one-item list when all errors are on' => [true, ['errors' => ['subject' => 'Required.']], [], '{"subject":["Required."]}'];

        yield 'a message list with gaps in its keys is sent as a list' => [true, ['errors' => ['subject' => [2 => 'Required.', 5 => 'Too short.']]], [], '{"subject":["Required.","Too short."]}'];

        yield 'numeric field names under an error bag stay an object' => [false, ['errors' => ['0' => 'Taken.']], $bag, '{"createUser":{"0":"Taken."}}'];

        yield 'no errors is an object' => [false, [], [], '{}'];

        yield 'no errors is an object when all errors are on' => [true, [], [], '{}'];

        yield 'the error bag header scopes the errors' => [false, ['errors' => ['email' => 'Taken.']], $bag, '{"createUser":{"email":"Taken."}}'];

        yield 'the error bag header without errors is an empty object' => [false, [], $bag, '{}'];

        yield 'an empty error bag header leaves the flat map' => [false, ['errors' => ['email' => 'Taken.']], ['X-Inertia-Error-Bag' => ''], '{"email":"Taken."}'];

        yield 'the error bag header with all errors on' => [true, ['errors' => ['email' => 'Taken.']], $bag, '{"createUser":{"email":["Taken."]}}'];
    }

    public function testErrorsAreEmptyAndNoSessionIsStartedWhenTheRequestCarriesNone(): void
    {
        unset($_SESSION);
        BaseService::resetSingle('session');

        $errors = $this->sharedErrors($this->inertiaGetRequest());

        $this->assertSame([], get_object_vars($errors));
        $this->assertFalse(BaseService::has('session'), 'The session service was constructed.');
        $this->assertFalse(isset($_SESSION));
    }

    public function testErrorsAreReadFromTheSessionWhenTheRequestCarriesTheSessionCookie(): void
    {
        unset($_SESSION);
        BaseService::injectMock('session', new class () {
            public function get(string $key): mixed
            {
                return $key === 'errors' ? ['subject' => 'Required.'] : null;
            }
        });
        $request = $this->inertiaGetRequest();
        $request->setGlobal('cookie', [(string) config('Session')->cookieName => 'abc']);

        $this->assertSame(['subject' => 'Required.'], get_object_vars($this->sharedErrors($request)));
    }

    public function testErrorsAreReadFromASessionTheApplicationAlreadyStarted(): void
    {
        $_SESSION = [];
        BaseService::injectMock('session', new class () {
            public function get(string $key): mixed
            {
                return $key === 'errors' ? ['subject' => 'Required.'] : null;
            }
        });

        $this->assertSame(['subject' => 'Required.'], get_object_vars($this->sharedErrors($this->inertiaGetRequest())));
    }

    /**
     * Resolves the shared `errors` prop the way a render does, without a response.
     */
    private function sharedErrors(IncomingRequest $request): object
    {
        $filter = new class () extends InertiaFilter {
            public function sharedProps(IncomingRequest $request): array
            {
                return $this->share($request);
            }
        };

        return ($filter->sharedProps($request)['errors'])();
    }

    public function testValidationErrorsFromWithInputReachTheNextInertiaPage(): void
    {
        $this->withRoutes([
            ['POST', 'form', static function () {
                $validation = service('validation');
                $validation->setRules(['email' => 'required|valid_email']);

                if ($validation->run(['email' => 'nope'])) {
                    return service('response')->setBody('saved');
                }

                return redirect()->to('/page')->withInput();
            }, ['filter' => 'inertia']],
            $this->renderingRoute(),
        ]);

        $this->post('/form', ['email' => 'nope'])->assertRedirectTo('/page');

        $this->resetPerRequestServices();
        $this->withRoutes([$this->renderingRoute()]);

        $result = $this->withSession($_SESSION)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => self::CURRENT_VERSION])
            ->get('/page');

        $result->assertStatus(200);

        $page = json_decode((string) $result->response()->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['email'], array_keys($page['props']['errors']));
        $this->assertNotSame('', $page['props']['errors']['email']);
    }

    /**
     * A filter whose `version()` counts its calls and whose `share()` adds a `flavor` prop.
     */
    private function countingFilter(): object
    {
        return new class () extends InertiaFilter {
            public int $versionCalls = 0;

            protected function version(IncomingRequest $request): string
            {
                $this->versionCalls++;

                return 'filter-version';
            }

            protected function share(IncomingRequest $request): array
            {
                return parent::share($request) + ['flavor' => 'vanilla'];
            }
        };
    }

    public function testPreparingAnExceptionRenderInstallsTheFiltersVersionAndSharesNothingByDefault(): void
    {
        $filter  = $this->countingFilter();
        $inertia = new ResponseFactory($this->inertiaGetRequest(), 'app', static fn (): string => 'config-version');

        $filter->prepareExceptionRender($this->inertiaGetRequest(), $inertia, false);

        $this->assertSame('filter-version', $inertia->getVersion());
        $this->assertSame([], $inertia->getShared());
    }

    public function testPreparingAnExceptionRenderCanShareEveryEntryOfShare(): void
    {
        $filter  = $this->countingFilter();
        $inertia = new ResponseFactory($this->inertiaGetRequest(), 'app', static fn (): string => 'config-version');

        $filter->prepareExceptionRender($this->inertiaGetRequest(), $inertia, true);

        $this->assertSame('filter-version', $inertia->getVersion());
        $this->assertSame(['errors', 'flavor'], array_keys($inertia->getShared()));
        $this->assertSame('vanilla', $inertia->getShared('flavor'));
    }

    public function testTheVersionInstalledForAnExceptionRenderIsResolvedAtMostOnce(): void
    {
        $filter  = $this->countingFilter();
        $inertia = new ResponseFactory($this->inertiaGetRequest(), 'app', static fn (): string => 'config-version');

        $filter->prepareExceptionRender($this->inertiaGetRequest(), $inertia, false);

        $this->assertSame(0, $filter->versionCalls);

        $inertia->getVersion();
        $inertia->getVersion();

        $this->assertSame(1, $filter->versionCalls);
    }

    public function testTheVaryRuleIsSharedWithAnyCaller(): void
    {
        $response = InertiaFilter::applyVaryHeader($this->renderedPageResponse());
        InertiaFilter::applyVaryHeader($response);

        $this->assertSame('X-Inertia', $response->getHeaderLine('Vary'));
    }

    private function injectSsrGateway(): HttpGateway
    {
        $config                        = new InertiaConfig();
        $config->ssrEnabled            = true;
        $config->ssrEnsureBundleExists = false;
        $config->ssrHotFile            = '/nonexistent/hot';

        $gateway = new HttpGateway(
            $config,
            function (array $options): CURLRequest {
                $this->ssrRequests++;

                return (new MockCURLRequest(config(AppConfig::class), new URI(), service('response', null, false), $options))
                    ->setOutput("HTTP/1.1 200 OK\r\n\r\n{}");
            },
            new BundleDetector($config),
        );
        BaseService::injectMock('inertiaSsrGateway', $gateway);

        return $gateway;
    }

    private function dispatchSsr(HttpGateway $gateway, string $route): void
    {
        $config            = new AppConfig();
        $config->indexPage = '';
        $gateway->dispatch(['component' => 'C'], new IncomingRequest($config, new SiteURI($config, $route), null, new UserAgent()));
    }

    private function runBefore(InertiaFilter $filter): void
    {
        $config            = new AppConfig();
        $config->indexPage = '';
        $filter->before(new IncomingRequest($config, new SiteURI($config, 'page'), null, new UserAgent()));
    }

    public function testAnExtendingFilterExcludesItsPathsFromSsr(): void
    {
        $gateway = $this->injectSsrGateway();

        $this->runBefore(new class () extends InertiaFilter {
            protected array $withoutSsr = ['admin/*'];
        });

        $this->dispatchSsr($gateway, 'admin/x');
        $this->assertSame(0, $this->ssrRequests);
        $this->dispatchSsr($gateway, 'users');
        $this->assertSame(1, $this->ssrRequests);
    }

    public function testAFilterWithoutPathsLeavesTheGatewayUntouched(): void
    {
        $gateway = $this->injectSsrGateway();

        $this->runBefore(new InertiaFilter());

        $this->dispatchSsr($gateway, 'admin/x');
        $this->assertSame(1, $this->ssrRequests);
    }

    public function testAFilterWithPathsLeavesAGatewayThatCannotExcludeThemAlone(): void
    {
        $gateway = new class () implements Gateway {
            public function dispatch(array $page): ?Response
            {
                return null;
            }
        };
        BaseService::injectMock('inertiaSsrGateway', $gateway);

        $this->runBefore(new class () extends InertiaFilter {
            protected array $withoutSsr = ['admin/*'];
        });

        $this->assertSame($gateway, service('inertiaSsrGateway'));
    }
}
