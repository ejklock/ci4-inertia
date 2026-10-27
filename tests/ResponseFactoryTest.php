<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use ArrayObject;
use BackedEnum;
use CodeIgniter\Config\BaseService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App as AppConfig;
use Config\Services;
use Ejklock\Ci4Inertia\Config\Inertia as InertiaConfig;
use Ejklock\Ci4Inertia\ProvidesInertiaProperties;
use Ejklock\Ci4Inertia\RenderContext;
use Ejklock\Ci4Inertia\ResponseFactory;
use Ejklock\Ci4Inertia\Support\SessionKey;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use RuntimeException;
use stdClass;
use UnitEnum;

/**
 * Verifies `Ejklock\Ci4Inertia\ResponseFactory` against the public API in
 * docs/architecture/inertia.md (render, share, version, location) and the
 * performance-budget rules: shared/per-render prop precedence, the acceptance
 * of closure and plain-object props, the version
 * resolver invoked at most once per instance regardless of render() call or prop count,
 * and `location()`'s 409-versus-redirect branch.
 * Also covers the package's default config and the `inertia` service that builds the factory from it.
 *
 * @internal
 */
final class ResponseFactoryTest extends CIUnitTestCase
{
    private bool $sessionGlobalExisted = false;

    /**
     * @var array<array-key, mixed>
     */
    private array $sessionGlobalBefore = [];

    /**
     * @param array<string, string> $headers
     */
    private function makeRequest(string $path = '', array $headers = []): IncomingRequest
    {
        $config  = config(AppConfig::class);
        $request = new IncomingRequest($config, new SiteURI($config, $path), null, new UserAgent());

        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }

        return $request;
    }

    /**
     * @psalm-suppress InvalidNullableReturnType, NullableReturnStatement the framework's service() docblock admits null
     */
    private function makeResponse(): ResponseInterface
    {
        return service('response', null, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function renderPageObject(ResponseFactory $factory, BackedEnum|string|UnitEnum $component, array $props = []): array
    {
        $response = $factory->render($component, $props)->toResponse(
            $this->makeRequest('users', ['X-Inertia' => 'true']),
            $this->makeResponse(),
        );

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testRenderMergesSharedPropsWithPerRenderPropsAndPerRenderWinsOnCollision(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $factory->share(['auth' => ['name' => 'Ada'], 'flash' => 'shared-value']);

        $page = $this->renderPageObject($factory, 'Users/Index', [
            'flash' => 'per-render-value',
            'users' => ['a'],
        ]);

        $this->assertSame([
            'auth'  => ['name' => 'Ada'],
            'flash' => 'per-render-value',
            'users' => ['a'],
        ], $page['props']);
    }

    public function testDeferBuildsADeferPropInTheGivenGroup(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $prop = $factory->defer(static fn (): int => 7, 'g');

        $this->assertSame('g', $prop->group());
        $this->assertSame(7, $prop());
    }

    public function testDeferWithoutAGroupUsesTheDefaultGroup(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $this->assertSame('default', $factory->defer(static fn (): int => 7)->group());
    }

    public function testOnceBuildsAOncePropThatResolvesOnceByDefault(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $prop = $factory->once(static fn (): int => 4);

        $this->assertTrue($prop->shouldResolveOnce());
        $this->assertSame(4, $prop());
    }

    public function testShareOnceSharesTheSameInstanceUnderTheKeyAndListsIt(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $prop = $factory->shareOnce('plans', static fn (): int => 4);
        $page = $this->renderPageObject($factory, 'Users/Index');

        $this->assertSame($prop, $factory->getShared('plans'));
        $this->assertSame(4, $page['props']['plans']);
        $this->assertSame(['plans' => ['prop' => 'plans', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testShareOnceWithADotKeyNestsTheProp(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $prop = $factory->shareOnce('auth.plans', static fn (): int => 4);

        $this->assertSame($prop, $factory->getShared('auth.plans'));
        $this->assertSame(['auth' => ['plans' => 4]], $this->renderPageObject($factory, 'Users/Index')['props']);
    }

    public function testSharedDeferredPropIsListedAndLeftOutOfTheNextRender(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');
        $factory->share('stats', $factory->defer(static fn (): int => 3, 'sidebar'));

        $page = $this->renderPageObject($factory, 'Users/Index', ['users' => ['a']]);

        $this->assertSame(['users' => ['a']], $page['props']);
        $this->assertSame(['sidebar' => ['stats']], $page['deferredProps']);
    }

    public function testPerRenderValueOverridingASharedDeferredPropIsSentAndNotListedAsDeferred(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');
        $factory->share('stats', $factory->defer(static fn (): int => 3));

        $page = $this->renderPageObject($factory, 'Users/Index', ['stats' => 9]);

        $this->assertSame(['stats' => 9], $page['props']);
        $this->assertArrayNotHasKey('deferredProps', $page);
    }

    public function testRenderResolvesAClosurePropToItsReturnValue(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $page = $this->renderPageObject($factory, 'Users/Index', [
            'counts' => static fn (): array => ['open' => 3],
        ]);

        $this->assertSame(['open' => 3], $page['props']['counts']);
    }

    public function testRenderAndShareAcceptAPlainObjectAndEncodeItAsJson(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');
        $shared  = new stdClass();

        $shared->name = 'Ada';
        $factory->share('shared', $shared);

        $rendered       = new stdClass();
        $rendered->size = 2;

        $page = $this->renderPageObject($factory, 'Users/Index', ['rendered' => $rendered]);

        $this->assertSame(['shared' => ['name' => 'Ada'], 'rendered' => ['size' => 2]], $page['props']);
    }

    public function testShareResolvesAClosureOnlyWhenTheRenderSendsIt(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');
        $calls   = new ArrayObject();

        $factory->share('lazy', static function () use ($calls): int {
            $calls->append('called');

            return 4;
        });

        $this->assertCount(0, $calls);
        $this->assertSame(['lazy' => 4], $this->renderPageObject($factory, 'Users/Index')['props']);
        $this->assertCount(1, $calls);
    }

    public function testShareBatchFormSharesEveryEntryAcrossSubsequentRenders(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $factory->share(['a' => 1, 'b' => 2]);

        $page = $this->renderPageObject($factory, 'Users/Index');

        $this->assertSame(['a' => 1, 'b' => 2], $page['props']);
    }

    public function testVersionAcceptsALiteralStringUsedVerbatimOnTheNextRender(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'initial');

        $factory->version('literal-version');

        $page = $this->renderPageObject($factory, 'Users/Index');

        $this->assertSame('literal-version', $page['version']);
    }

    public function testVersionAcceptsACallableResolverUsedVerbatimOnTheNextRender(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'initial');

        $factory->version(static fn (): string => 'resolved-by-callable');

        $page = $this->renderPageObject($factory, 'Users/Index');

        $this->assertSame('resolved-by-callable', $page['version']);
    }

    public function testVersionResolverIsInvokedAtMostOnceRegardlessOfRenderCallCountOrPropCount(): void
    {
        $invocations = 0;
        $resolver    = static function () use (&$invocations): string {
            $invocations++;

            return 'v-' . $invocations;
        };

        $factory = new ResponseFactory($this->makeRequest(), 'app', $resolver);

        $factory->render('Users/Index', ['a' => 1]);
        $factory->render('Users/Index', ['a' => 1, 'b' => 2, 'c' => 3]);
        $factory->render('Users/Show');

        $this->assertSame(1, $invocations);
    }

    public function testLocationReturns409WithXInertiaLocationHeaderWhenRequestCarriesXInertia(): void
    {
        $factory = new ResponseFactory(
            $this->makeRequest('users', ['X-Inertia' => 'true']),
            'app',
            static fn (): string => 'v1',
        );

        $response = $factory->location('/users');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('/users', $response->getHeaderLine('X-Inertia-Location'));
        $this->assertFalse($response->hasHeader('Location'));
    }

    public function testLocationReturnsANormalRedirectWhenRequestDoesNotCarryXInertia(): void
    {
        $factory = new ResponseFactory($this->makeRequest('users'), 'app', static fn (): string => 'v1');

        $response = $factory->location('/users');

        $this->assertNotSame(409, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('X-Inertia-Location'));
        $this->assertSame((string) new SiteURI(config(AppConfig::class), '/users'), $response->getHeaderLine('Location'));
    }

    public function testDefaultVersionResolverReturnsAnEmptyString(): void
    {
        $config = new InertiaConfig();

        $this->assertSame('', ($config->version)());
    }

    public function testDefaultRootViewIsNamed(): void
    {
        $config = new InertiaConfig();

        $this->assertSame('app', $config->rootView);
    }

    public function testSharedPropKeysAreExposedByDefault(): void
    {
        $this->assertTrue((new InertiaConfig())->exposeSharedPropKeys);
    }

    public function testSharingADotKeyNestsItAndListsItsTopLevelKey(): void
    {
        $factory = $this->newFactory();

        $factory->share('auth.user', 'Ada');
        $page = $this->renderPageObject($factory, 'Users/Index');

        $this->assertSame('{"auth":{"user":"Ada"}}', json_encode($page['props']));
        $this->assertSame(['auth'], $page['sharedProps']);
    }

    public function testSharingDotKeysIntoTheSameParentKeepsBoth(): void
    {
        $factory = $this->newFactory();

        $factory->share('auth.user', 'Ada');
        $factory->share('auth.role', 'admin');
        $page = $this->renderPageObject($factory, 'Users/Index');

        $this->assertSame(['auth' => ['user' => 'Ada', 'role' => 'admin']], $page['props']);
        $this->assertSame(['auth'], $page['sharedProps']);
    }

    public function testSharingADotKeyOverAScalarReplacesTheScalar(): void
    {
        $factory = $this->newFactory();

        $factory->share('auth', 'x');
        $factory->share('auth.user', 'Ada');

        $this->assertSame(['auth' => ['user' => 'Ada']], $this->renderPageObject($factory, 'Users/Index')['props']);
    }

    public function testSharedKeysAreListedInShareOrder(): void
    {
        $factory = $this->newFactory();

        $factory->share(['flash' => 1, 'app' => 2]);
        $page = $this->renderPageObject($factory, 'Users/Index');

        $this->assertSame(['flash', 'app'], $page['sharedProps']);
    }

    public function testASharedPropertiesProviderListsTheKeysItProvides(): void
    {
        $factory = $this->newFactory();

        $factory->share(new class () implements ProvidesInertiaProperties {
            public function toInertiaProperties(RenderContext $context): iterable
            {
                return ['locale' => 'pt', 'menu' => [1]];
            }
        });
        $page = $this->renderPageObject($factory, 'Users/Index');

        $this->assertSame(['locale' => 'pt', 'menu' => [1]], $page['props']);
        $this->assertSame(['locale', 'menu'], $page['sharedProps']);
    }

    public function testAPagePropWithASharedKeyWinsAndTheKeyIsListedOnce(): void
    {
        $factory = $this->newFactory();

        $factory->share('flash', 'shared');
        $page = $this->renderPageObject($factory, 'Users/Index', ['flash' => 'page']);

        $this->assertSame(['flash' => 'page'], $page['props']);
        $this->assertSame(['flash'], $page['sharedProps']);
    }

    public function testNoSharedPropsMeansNoSharedPropsKey(): void
    {
        $page = $this->renderPageObject($this->newFactory(), 'Users/Index', ['a' => 1]);

        $this->assertArrayNotHasKey('sharedProps', $page);
    }

    public function testTurningSharedPropKeysOffOmitsThemAndKeepsTheProps(): void
    {
        $config = config('Inertia');
        $before = $config->exposeSharedPropKeys;

        try {
            $config->exposeSharedPropKeys = false;
            $factory                      = $this->newFactory();
            $factory->share(['flash' => 1, 'app' => 3]);
            $page = $this->renderPageObject($factory, 'Users/Index', ['a' => 2]);
        } finally {
            $config->exposeSharedPropKeys = $before;
        }

        $this->assertArrayNotHasKey('sharedProps', $page);
        $this->assertSame(['flash' => 1, 'app' => 3, 'a' => 2], $page['props']);
    }

    public function testSharedDotKeysUnderOneParentListThatParentOnceAndTheListStaysAList(): void
    {
        $factory = $this->newFactory();

        $factory->share(['auth.id' => 1, 'auth.name' => 'Ada', 'flash' => 2]);
        $page = $this->renderPageObject($factory, 'Users/Index');

        $this->assertSame(['flash' => 2, 'auth' => ['id' => 1, 'name' => 'Ada']], $page['props']);
        $this->assertSame(['auth', 'flash'], $page['sharedProps']);
    }

    public function testSharingAfterAnArrayShareKeepsEarlierSharedProps(): void
    {
        $factory = $this->newFactory();

        $factory->share('a', 1);
        $factory->share(['b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $this->renderPageObject($factory, 'Users/Index')['props']);
    }

    public function testSharedPropKeysComeRightAfterTheVersion(): void
    {
        $factory = $this->newFactory();

        $factory->share('flash', 1);
        $page = $this->renderPageObject($factory, 'Users/Index', ['feed' => $factory->merge([1])]);

        $this->assertSame(['component', 'props', 'url', 'version', 'sharedProps', 'mergeProps'], array_keys($page));
    }

    public function testTheInertiaServiceBuildsTheFactoryFromTheConfiguredVersion(): void
    {
        $config          = config('Inertia');
        $originalVersion = $config->version;
        $config->version = static fn (): string => 'configured-version';

        try {
            $factory = service('inertia', false);

            $this->assertSame('configured-version', $this->renderPageObject($factory, 'Users/Index')['version']);
        } finally {
            $config->version = $originalVersion;
        }
    }

    public function testMergeBuildsAnAppendPropResolvingToTheGivenArray(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $page = $this->renderPageObject($factory, 'Users/Index', ['users' => $factory->merge([1])]);

        $this->assertSame([1], $page['props']['users']);
        $this->assertSame(['users'], $page['mergeProps']);
        $this->assertArrayNotHasKey('deepMergeProps', $page);
    }

    public function testMergeInvokesAClosureOnlyWhenTheValueIsResolved(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');
        $calls   = new ArrayObject();
        $prop    = $factory->merge(static function () use ($calls): array {
            $calls->append('resolved');

            return [2];
        });

        $this->assertCount(0, $calls);
        $this->assertSame([2], $prop());
        $this->assertCount(1, $calls);
    }

    public function testDeepMergeBuildsAPropListedAsDeepMerged(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $page = $this->renderPageObject($factory, 'Users/Index', ['conf' => $factory->deepMerge(['a' => 1])]);

        $this->assertSame(['conf'], $page['deepMergeProps']);
        $this->assertArrayNotHasKey('mergeProps', $page);
    }

    public function testSharedMergePropIsListedOnTheNextRender(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');
        $factory->share('notices', $factory->merge([1]));

        $page = $this->renderPageObject($factory, 'Users/Index');

        $this->assertSame(['notices'], $page['mergeProps']);
    }

    public function testTheInertiaServiceRendersTheConfiguredRootView(): void
    {
        $config           = config('Inertia');
        $originalRootView = $config->rootView;
        $config->rootView = 'Ejklock\Ci4InertiaTestSupport\Views\shell';

        try {
            $html = (string) service('inertia', false)
                ->render('Users/Index')
                ->toResponse($this->makeRequest('users'), $this->makeResponse())
                ->getBody();

            $this->assertStringContainsString('Inertia test shell', $html);
        } finally {
            $config->rootView = $originalRootView;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionGlobalExisted = isset($_SESSION);
        $this->sessionGlobalBefore  = $_SESSION ?? [];
    }

    protected function tearDown(): void
    {
        Services::resetSingle('session');
        service('superglobals')->setGlobalArray('cookie', []);

        if ($this->sessionGlobalExisted) {
            $_SESSION = $this->sessionGlobalBefore;
        } else {
            unset($_SESSION);
        }

        parent::tearDown();
    }

    private function newFactory(?IncomingRequest $request = null, bool $encryptHistory = false): ResponseFactory
    {
        return new ResponseFactory(
            $request ?? $this->makeRequest('users'),
            'app',
            static fn (): string => 'v1',
            $encryptHistory,
        );
    }

    private function makeRequestWithSessionCookie(): IncomingRequest
    {
        $request = $this->makeRequest('users', ['X-Inertia' => 'true']);
        $request->setGlobal('cookie', [(string) config('Session')->cookieName => 'abc']);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderPageFrom(ResponseFactory $factory, IncomingRequest $request): array
    {
        $response = $factory->render('Users/Index')->toResponse($request, $this->makeResponse());

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testHistoryEncryptionIsAbsentByDefault(): void
    {
        $this->assertArrayNotHasKey('encryptHistory', $this->renderPageObject($this->newFactory(), 'Users/Index'));
    }

    public function testHistoryEncryptionIsAbsentWhenTheFactoryIsBuiltWithoutTheOption(): void
    {
        $factory = new ResponseFactory($this->makeRequest('users'), 'app', static fn (): string => 'v1');

        $this->assertArrayNotHasKey('encryptHistory', $this->renderPageObject($factory, 'Users/Index'));
    }

    public function testHistoryEncryptionFollowsTheConfiguredDefaultThroughTheService(): void
    {
        $config                 = config('Inertia');
        $original               = $config->encryptHistory;
        $config->encryptHistory = true;

        try {
            $page = $this->renderPageObject(service('inertia', false), 'Users/Index');

            $this->assertTrue($page['encryptHistory']);
        } finally {
            $config->encryptHistory = $original;
        }
    }

    public function testHistoryEncryptionCanBeSwitchedOffForLaterRenders(): void
    {
        $factory = $this->newFactory(null, true);
        $factory->encryptHistory(false);

        $this->assertArrayNotHasKey('encryptHistory', $this->renderPageObject($factory, 'Users/Index'));
    }

    public function testHistoryEncryptionCanBeSwitchedOnForLaterRendersOnly(): void
    {
        $factory = $this->newFactory();
        $before  = $this->renderPageObject($factory, 'Users/Index');
        $factory->encryptHistory();
        $after = $this->renderPageObject($factory, 'Users/Index');

        $this->assertArrayNotHasKey('encryptHistory', $before);
        $this->assertTrue($after['encryptHistory']);
    }

    public function testHistoryClearingFiresOnTheNextRenderOnlyOnce(): void
    {
        $this->mockSession();
        $factory = $this->newFactory();
        $factory->clearHistory();

        $first  = $this->renderPageObject($factory, 'Users/Index');
        $second = $this->renderPageObject($factory, 'Users/Index');

        $this->assertTrue($first['clearHistory']);
        $this->assertArrayNotHasKey('clearHistory', $second);
    }

    public function testHistoryClearingRequestedBeforeARedirectFiresOnTheNextRequest(): void
    {
        $this->mockSession();
        $this->newFactory()->clearHistory();

        $request = $this->makeRequestWithSessionCookie();
        $page    = $this->renderPageFrom($this->newFactory($request), $request);
        $again   = $this->renderPageFrom($this->newFactory($request), $request);

        $this->assertTrue($page['clearHistory']);
        $this->assertArrayNotHasKey('clearHistory', $again);
        $this->assertSame([], $_SESSION);
    }

    /**
     * Injects a session holding pending data independently of `$_SESSION`, and leaves `$_SESSION` unset,
     * as on the request after a redirect before anything has started the session.
     *
     * @param array<string, mixed> $data
     */
    private function injectPendingSession(array $data): void
    {
        unset($_SESSION);

        Services::injectMock('session', new class ($data) {
            /**
             * @param array<string, mixed> $data
             */
            public function __construct(private array $data)
            {
            }

            public function has(string $key): bool
            {
                return isset($this->data[$key]);
            }

            public function get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function getFlashdata(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function remove(string $key): void
            {
                unset($this->data[$key]);
            }

            public function unmarkFlashdata(string $key): void
            {
            }
        });
    }

    public function testARequestCarryingOnlyTheSessionCookieRendersPendingFlashData(): void
    {
        $this->injectPendingSession(['inertia.flash_data' => ['toast' => 'Saved']]);

        $request = $this->makeRequestWithSessionCookie();

        $this->assertSame(['toast' => 'Saved'], $this->renderPageFrom($this->newFactory($request), $request)['flash']);
    }

    public function testARequestCarryingOnlyTheSessionCookieRendersThePendingPreserveFragmentFlag(): void
    {
        $this->injectPendingSession(['inertia.preserve_fragment' => true]);

        $request = $this->makeRequestWithSessionCookie();

        $this->assertTrue($this->renderPageFrom($this->newFactory($request), $request)['preserveFragment']);
    }

    public function testARequestCarryingOnlyTheSessionCookieRendersThePendingClearHistoryFlag(): void
    {
        $this->injectPendingSession(['inertia.clear_history' => true]);

        $request = $this->makeRequestWithSessionCookie();

        $this->assertTrue($this->renderPageFrom($this->newFactory($request), $request)['clearHistory']);
    }

    public function testHistoryClearingIsIgnoredWithoutACookieAnExistingSessionOrAWrite(): void
    {
        BaseService::resetSingle('session');
        unset($_SESSION);

        $request = $this->makeRequest('users', ['X-Inertia' => 'true']);
        $page    = $this->renderPageFrom($this->newFactory($request), $request);

        $this->assertArrayNotHasKey('clearHistory', $page);
        $this->assertFalse(BaseService::has('session'), 'The session service was constructed.');
    }

    public function testHistoryClearingIsHonouredForASessionTheRequestAlreadyStarted(): void
    {
        $this->mockSession();
        $_SESSION['inertia.clear_history'] = true;

        $request = $this->makeRequest('users', ['X-Inertia' => 'true']);
        $page    = $this->renderPageFrom($this->newFactory($request), $request);

        $this->assertTrue($page['clearHistory']);
    }

    public function testHistoryRenderWithoutReasonNeverTouchesTheSession(): void
    {
        Services::injectMock('session', new class () {
            public function __call(string $name, array $arguments): never
            {
                Assert::fail("The session service was used through {$name}().");
            }

            public function __get(string $name): never
            {
                Assert::fail("The session service was read through property {$name}.");
            }

            public function __set(string $name, mixed $value): never
            {
                Assert::fail("The session service was written through property {$name}.");
            }

            public function __isset(string $name): never
            {
                Assert::fail("The session service was probed through property {$name}.");
            }

            public function __unset(string $name): never
            {
                Assert::fail("The session service was unset through property {$name}.");
            }

            public function __invoke(): never
            {
                Assert::fail('The session service was invoked.');
            }
        });

        unset($_SESSION);

        $page = $this->renderPageObject($this->newFactory(), 'Users/Index');

        $this->assertSame('Users/Index', $page['component']);
    }

    public function testGetSharedReturnsEverythingSharedWhenNoKeyIsGiven(): void
    {
        $factory = $this->newFactory();
        $factory->share(['auth' => ['user' => 'Ada']]);

        $this->assertSame(['auth' => ['user' => 'Ada']], $factory->getShared());
        $this->assertSame(['auth' => ['user' => 'Ada']], $factory->getShared(''));
    }

    public function testGetSharedReadsADotPathAndFallsBackToTheDefault(): void
    {
        $factory = $this->newFactory();
        $factory->share(['auth' => ['user' => 'Ada']]);

        $this->assertSame('Ada', $factory->getShared('auth.user'));
        $this->assertSame('x', $factory->getShared('missing', 'x'));
        $this->assertNull($factory->getShared('missing'));
        $this->assertSame('x', $factory->getShared('auth.user.name', 'x'));
        $this->assertSame('x', $factory->getShared('auth.nobody', 'x'));
    }

    public function testGetSharedPrefersALiteralKeyContainingADot(): void
    {
        $factory = $this->newFactory();
        $factory->share(['a.b' => 1]);

        $this->assertSame(1, $factory->getShared('a.b'));
    }

    public function testGetSharedReturnsASharedNullInsteadOfTheDefault(): void
    {
        $factory = $this->newFactory();
        $factory->share('nothing');
        $factory->share('group', ['inner' => null]);

        $this->assertNull($factory->getShared('nothing', 'x'));
        $this->assertNull($factory->getShared('group.inner', 'x'));
    }

    public function testGetSharedCallsAClosureDefaultOnlyForAMissingKey(): void
    {
        $calls   = new ArrayObject();
        $default = static function () use ($calls): string {
            $calls->append('called');

            return 'x';
        };
        $factory = $this->newFactory();
        $factory->share(['a' => 1]);

        $this->assertSame(1, $factory->getShared('a', $default));
        $this->assertCount(0, $calls);
        $this->assertSame('x', $factory->getShared('missing', $default));
        $this->assertSame('x', $factory->getShared('a.b.c', $default));
        $this->assertCount(2, $calls);
    }

    public function testGetSharedReturnsANonClosureCallableDefaultAsIs(): void
    {
        $factory = $this->newFactory();

        $this->assertSame('strtoupper', $factory->getShared('missing', 'strtoupper'));
    }

    public function testGetSharedWalksADotPathThroughAnArrayAccessObject(): void
    {
        $factory = $this->newFactory();
        $factory->share(['bag' => new ArrayObject(['k' => 'v', 'n' => null])]);

        $this->assertSame('v', $factory->getShared('bag.k'));
        $this->assertNull($factory->getShared('bag.n', 'x'));
        $this->assertSame('x', $factory->getShared('bag.missing', 'x'));
    }

    public function testFlushSharedDropsEverySharedPropFromLaterRenders(): void
    {
        $factory = $this->newFactory();
        $factory->share(['a' => 1]);
        $factory->flushShared();

        $page = $this->renderPageObject($factory, 'Users/Index');

        $this->assertSame([], $factory->getShared());
        $this->assertSame([], $page['props']);
        $this->assertArrayNotHasKey('sharedProps', $page);
    }

    public function testGetVersionResolvesOnceAcrossLaterRenders(): void
    {
        $invocations = 0;
        $factory     = new ResponseFactory($this->makeRequest('users'), 'app', static function () use (&$invocations): string {
            $invocations++;

            return 'v9';
        });

        $this->assertSame('v9', $factory->getVersion());
        $this->assertSame('v9', $this->renderPageObject($factory, 'C')['version']);
        $this->assertSame(1, $invocations);
    }

    public function testVersionAfterGetVersionReplacesTheValue(): void
    {
        $factory = $this->newFactory();

        $this->assertSame('v1', $factory->getVersion());

        $factory->version('v2');

        $this->assertSame('v2', $factory->getVersion());
    }

    public function testOptionalBuildsAPropSentOnlyOnAPartialReloadNamingIt(): void
    {
        $factory = $this->newFactory();
        $prop    = $factory->optional(static fn (): int => 1);

        $this->assertArrayNotHasKey('lazy', $this->renderPageObject($factory, 'C', ['lazy' => $prop]));

        $page = $this->renderPartial($factory, ['lazy' => $prop], 'lazy');

        $this->assertSame(['lazy' => 1], $page['props']);
    }

    public function testAlwaysBuildsAPropSentOnAPartialReloadNamingAnotherKey(): void
    {
        $factory = $this->newFactory();
        $prop    = $factory->always('x');

        $page = $this->renderPartial($factory, ['keep' => $prop, 'other' => 2], 'other');

        $this->assertSame(['keep' => 'x', 'other' => 2], $page['props']);
    }

    private function newSessionlessFactory(): ResponseFactory
    {
        BaseService::resetSingle('session');
        unset($_SESSION);

        return $this->newFactory();
    }

    public function testFlashDataIsCarriedByTheNextRenderOfTheSameRequestAndConsumedByIt(): void
    {
        $this->mockSession();
        $factory = $this->newFactory();
        $factory->flash('toast', 'Saved');

        $page = $this->renderPageObject($factory, 'Users/Index');

        $this->assertSame(['toast' => 'Saved'], $page['flash']);
        $this->assertArrayNotHasKey('inertia.flash_data', $_SESSION ?? []);
        $this->assertArrayNotHasKey('flash', $this->renderPageObject($factory, 'Users/Index'));
    }

    public function testFlashDataSetInOneRequestIsPresentInTheNextRenderAndAbsentAfterIt(): void
    {
        $this->mockSession();
        $this->newFactory()->flash('toast', 'Saved');

        $request = $this->makeRequestWithSessionCookie();
        $page    = $this->renderPageFrom($this->newFactory($request), $request);
        $again   = $this->renderPageFrom($this->newFactory($request), $request);

        $this->assertSame(['toast' => 'Saved'], $page['flash']);
        $this->assertArrayNotHasKey('flash', $again);
    }

    public function testFlashMergesNewDataOverWhatIsAlreadyFlashed(): void
    {
        $this->mockSession();
        $factory = $this->newFactory();
        $factory->flash(['a' => 1]);
        $factory->flash('b', 2);

        $this->assertSame(['a' => 1, 'b' => 2], $factory->getFlashed());

        $factory->flash('a', 3);

        $this->assertSame(['a' => 3, 'b' => 2], $this->renderPageObject($factory, 'Users/Index')['flash']);
    }

    public function testFlashReadsAStringBackedEnumKeyByItsValueAndAPureEnumKeyByItsName(): void
    {
        $this->mockSession();
        $factory = $this->newFactory();
        $factory->flash(TestPage::Users, 'x');
        $factory->flash(TestPureEnum::Dashboard, 'y');

        $this->assertSame(['Users/Index' => 'x', 'Dashboard' => 'y'], $factory->getFlashed());
    }

    public function testFlashReturnsTheFactory(): void
    {
        $this->mockSession();
        $factory = $this->newFactory();

        $this->assertSame($factory, $factory->flash('k', 'v'));
    }

    public function testAResponseFlashReachesItsOwnPageAndReturnsTheResponse(): void
    {
        $this->mockSession();
        $factory  = $this->newFactory();
        $response = $factory->render('Users/Index');

        $this->assertSame($response, $response->flash('k', 'v'));

        $page = json_decode((string) $response->toResponse($this->makeRequest('users', ['X-Inertia' => 'true']), $this->makeResponse())->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['k' => 'v'], $page['flash']);
    }

    public function testGetFlashedReadsWithoutRemovingAndPullFlashedRemoves(): void
    {
        $this->mockSession();
        $factory = $this->newFactory();
        $factory->flash('k', 'v');

        $this->assertSame(['k' => 'v'], $factory->getFlashed());
        $this->assertSame(['k' => 'v'], $factory->getFlashed());
        $this->assertSame(['k' => 'v'], $factory->pullFlashed());
        $this->assertSame([], $factory->getFlashed());
        $this->assertSame([], $factory->pullFlashed());
        $this->assertSame([], session()->getFlashdata());
    }

    public function testFlashDataIsNeverReadFromASessionTheRequestDoesNotCarry(): void
    {
        $factory = $this->newSessionlessFactory();

        $this->assertSame([], $factory->getFlashed());
        $this->assertSame([], $factory->pullFlashed());
        $this->assertArrayNotHasKey('flash', $this->renderPageObject($factory, 'Users/Index'));
        $this->assertFalse(BaseService::has('session'), 'The session service was constructed.');
    }

    public function testThePageKeysFollowTheMetadataInTheOrderClearHistoryEncryptHistoryFlashPreserveFragment(): void
    {
        $this->mockSession();
        $factory = $this->newFactory(null, true);
        $factory->preserveFragment();
        $factory->flash('k', 'v');
        $factory->clearHistory();

        $page = $this->renderPageObject($factory, 'Users/Index');

        $this->assertSame(['component', 'props', 'url', 'version', 'clearHistory', 'encryptHistory', 'flash', 'preserveFragment'], array_keys($page));
    }

    public function testPreserveFragmentFiresOnTheNextRequestOnly(): void
    {
        $this->mockSession();
        $this->newFactory()->preserveFragment();

        $request = $this->makeRequestWithSessionCookie();
        $page    = $this->renderPageFrom($this->newFactory($request), $request);
        $again   = $this->renderPageFrom($this->newFactory($request), $request);

        $this->assertTrue($page['preserveFragment']);
        $this->assertArrayNotHasKey('preserveFragment', $again);
        $this->assertSame([], $_SESSION);
    }

    public function testPageHasNoPreserveFragmentKeyWithoutTheCall(): void
    {
        $this->mockSession();

        $this->assertArrayNotHasKey('preserveFragment', $this->renderPageObject($this->newFactory(), 'Users/Index'));
    }

    public function testPreserveFragmentIsIgnoredWithoutACookieAnExistingSessionOrAWrite(): void
    {
        BaseService::resetSingle('session');
        unset($_SESSION);

        $request = $this->makeRequest('users', ['X-Inertia' => 'true']);

        $this->assertArrayNotHasKey('preserveFragment', $this->renderPageFrom($this->newFactory($request), $request));
        $this->assertFalse(BaseService::has('session'), 'The session service was constructed.');
    }

    public function testTheSessionKeysHoldLaravelsNames(): void
    {
        $this->assertSame(
            [
                'CLEAR_HISTORY'     => 'inertia.clear_history',
                'FLASH_DATA'        => 'inertia.flash_data',
                'PRESERVE_FRAGMENT' => 'inertia.preserve_fragment',
            ],
            (new ReflectionClass(SessionKey::class))->getConstants(),
        );
    }

    public function testBackRedirectsToTheReferer(): void
    {
        $request  = $this->makeRequest('users', ['Referer' => 'https://example.com/a?b=1']);
        $response = $this->newFactory($request)->back();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com/a?b=1', $response->getHeaderLine('Location'));
    }

    public function testBackTakesAStatusAndHeaders(): void
    {
        $request  = $this->makeRequest('users', ['Referer' => 'https://example.com/a']);
        $response = $this->newFactory($request)->back(303, ['X-A' => '1']);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('1', $response->getHeaderLine('X-A'));
    }

    public function testBackFallsBackToTheStoredPreviousUrlWithoutAReferer(): void
    {
        $this->mockSession();
        $_SESSION['_ci_previous_url'] = 'https://example.com/previous';
        $request                      = $this->makeRequestWithSessionCookie();

        $this->assertSame('https://example.com/previous', $this->newFactory($request)->back()->getHeaderLine('Location'));
    }

    public function testBackUsesTheFallbackWhenThereIsNoRefererOrPreviousUrl(): void
    {
        $this->mockSession();
        $request = $this->makeRequestWithSessionCookie();

        $this->assertSame(site_url('/home'), $this->newFactory($request)->back(302, [], '/home')->getHeaderLine('Location'));
        $this->assertSame(site_url('/'), $this->newFactory($request)->back()->getHeaderLine('Location'));
    }

    public function testBackNeverStartsASessionTheRequestDoesNotCarry(): void
    {
        $response = $this->newSessionlessFactory()->back();

        $this->assertSame(site_url('/'), $response->getHeaderLine('Location'));
        $this->assertFalse(BaseService::has('session'), 'The session service was constructed.');
    }

    public function testRenderAcceptsAStringBackedEnumAsTheComponent(): void
    {
        $this->assertSame('Users/Index', $this->renderPageObject($this->newFactory(), TestPage::Users)['component']);
    }

    public function testRenderUsesTheNameOfAPureEnumAsTheComponent(): void
    {
        $this->assertSame('Dashboard', $this->renderPageObject($this->newFactory(), TestPureEnum::Dashboard)['component']);
    }

    public function testRenderRejectsAnIntBackedEnumComponent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Component argument must be of type string or a string BackedEnum');

        $this->newFactory()->render(TestIntEnum::One);
    }

    public function testTransformComponentUsingRewritesTheComponentName(): void
    {
        $factory = $this->newFactory();
        $factory->transformComponentUsing(static fn ($component): string => 'Pages/' . $component);

        $this->assertSame('Pages/Users', $this->renderPageObject($factory, 'Users')['component']);
    }

    public function testATransformerReturningNullKeepsTheOriginalComponent(): void
    {
        $factory = $this->newFactory();
        $factory->transformComponentUsing(static fn ($component): ?string => null);

        $this->assertSame('Users', $this->renderPageObject($factory, 'Users')['component']);
    }

    public function testClearingTheTransformerStopsTransforming(): void
    {
        $factory = $this->newFactory();
        $factory->transformComponentUsing(static fn ($component): string => 'Pages/' . $component);
        $factory->transformComponentUsing();

        $this->assertSame('Users', $this->renderPageObject($factory, 'Users')['component']);
    }

    public function testTheTransformerRunsBeforeTheEnumMapping(): void
    {
        $factory = $this->newFactory();
        $factory->transformComponentUsing(static fn ($component): string => $component instanceof TestPage ? 'Custom/' . $component->name : 'wrong');

        $this->assertSame('Custom/Users', $this->renderPageObject($factory, TestPage::Users)['component']);
    }

    public function testSetRootViewChangesTheViewOfLaterRendersOnly(): void
    {
        $factory = new ResponseFactory($this->makeRequest('users'), 'missing-before', static fn (): string => 'v1');
        $before  = $factory->render('C');

        $factory->setRootView('Ejklock\Ci4InertiaTestSupport\Views\shell');
        $after = $factory->render('C');

        $html = (string) $after->toResponse($this->makeRequest('users'), $this->makeResponse())->getBody();

        $this->assertStringContainsString('Inertia test shell', $html);

        $this->expectException(RuntimeException::class);
        $before->toResponse($this->makeRequest('users'), $this->makeResponse());
    }

    public function testResolveUrlUsingSetsThePageUrlAndReceivesTheRequest(): void
    {
        $factory  = $this->newFactory();
        $received = null;
        $factory->resolveUrlUsing(static function (IncomingRequest $request) use (&$received): string {
            $received = $request;

            return '/custom';
        });
        $request = $this->makeRequest('users', ['X-Inertia' => 'true']);

        $page = json_decode((string) $factory->render('C')->toResponse($request, $this->makeResponse())->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/custom', $page['url']);
        $this->assertSame($request, $received);
    }

    public function testResolveUrlUsingNullRestoresTheDefaultUrl(): void
    {
        $factory = $this->newFactory();
        $factory->resolveUrlUsing(static fn (IncomingRequest $request): string => '/custom');
        $factory->resolveUrlUsing();

        $this->assertSame('/users', $this->renderPageObject($factory, 'C')['url']);
    }

    public function testLocationGivenARedirectAnswers409WithItsTargetOnAnInertiaRequest(): void
    {
        $factory = new ResponseFactory($this->makeRequest('users', ['X-Inertia' => 'true']), 'app', static fn (): string => 'v1');

        $response = $factory->location(redirect()->to('/y'));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame((string) new SiteURI(config(AppConfig::class), '/y'), $response->getHeaderLine('X-Inertia-Location'));
    }

    public function testLocationGivenARedirectReturnsThatRedirectOtherwise(): void
    {
        $factory  = $this->newFactory();
        $redirect = redirect()->to('/y');

        $this->assertSame($redirect, $factory->location($redirect));
    }

    /**
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    private function renderPartial(ResponseFactory $factory, array $props, string $only): array
    {
        $request = $this->makeRequest('users', [
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'C',
            'X-Inertia-Partial-Data'      => $only,
        ]);

        return json_decode((string) $factory->render('C', $props)->toResponse($request, $this->makeResponse())->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}

enum TestPage: string
{
    case Users = 'Users/Index';
}

enum TestPureEnum
{
    case Dashboard;
}

enum TestIntEnum: int
{
    case One = 1;
}
