<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use ArrayObject;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App as AppConfig;
use Config\Services;
use Ejklock\Ci4Inertia\Config\Inertia as InertiaConfig;
use Ejklock\Ci4Inertia\LazyProp;
use Ejklock\Ci4Inertia\ResponseFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * Verifies `Ejklock\Ci4Inertia\ResponseFactory` against the public API in
 * docs/architecture/inertia.md (render, share, version, location) and the egress-typing
 * and performance-budget rules: shared/per-render prop precedence, the throw
 * on a non scalar/array/null/ResolvableProp prop value naming its key, the version
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
    private function renderPageObject(ResponseFactory $factory, string $component, array $props = []): array
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
        $this->assertSame(7, $prop->resolve());
    }

    public function testDeferWithoutAGroupUsesTheDefaultGroup(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $this->assertSame('default', $factory->defer(static fn (): int => 7)->group());
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

    public function testRenderAcceptsResolvablePropValuesWithoutThrowing(): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $page = $this->renderPageObject($factory, 'Users/Index', [
            'counts' => new LazyProp(static fn (): array => ['open' => 3]),
        ]);

        $this->assertSame(['open' => 3], $page['props']['counts']);
    }

    #[DataProvider('provideInvalidPropValues')]
    public function testRenderThrowsForANonScalarArrayNullOrResolvablePropValueNamingTheOffendingKey(mixed $invalidValue): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('offender');

        $factory->render('Users/Index', ['offender' => $invalidValue]);
    }

    #[DataProvider('provideInvalidPropValues')]
    public function testShareThrowsForANonScalarArrayNullOrResolvablePropValueNamingTheOffendingKey(mixed $invalidValue): void
    {
        $factory = new ResponseFactory($this->makeRequest(), 'app', static fn (): string => 'v1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('offender');

        $factory->share('offender', $invalidValue);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function provideInvalidPropValues(): iterable
    {
        yield 'a plain object' => [new stdClass()];

        yield 'a raw closure not wrapped in a ResolvableProp' => [static fn (): string => 'value'];
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
        $this->assertSame([2], $prop->resolve());
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

    public function testHistoryClearingIsIgnoredWithoutTheSessionCookie(): void
    {
        $this->mockSession();
        $_SESSION['inertia.clear_history'] = true;

        $request = $this->makeRequest('users', ['X-Inertia' => 'true']);
        $page    = $this->renderPageFrom($this->newFactory($request), $request);

        $this->assertArrayNotHasKey('clearHistory', $page);
        $this->assertSame(['inertia.clear_history' => true], $_SESSION);
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

        $page = $this->renderPageObject($this->newFactory(), 'Users/Index');

        $this->assertSame('Users/Index', $page['component']);
    }
}
