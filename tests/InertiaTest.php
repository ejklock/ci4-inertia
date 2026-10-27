<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use ArrayObject;
use CodeIgniter\Config\BaseService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCURLRequest;
use Config\App as AppConfig;
use Config\Services;
use Ejklock\Ci4Inertia\Config\Inertia as InertiaConfig;
use Ejklock\Ci4Inertia\ExceptionResponse;
use Ejklock\Ci4Inertia\Inertia;
use Ejklock\Ci4Inertia\InertiaExceptionHandler;
use Ejklock\Ci4Inertia\InertiaResponse;
use Ejklock\Ci4Inertia\ProvidesInertiaProperties;
use Ejklock\Ci4Inertia\RenderContext;
use Ejklock\Ci4Inertia\ResponseFactory;
use Ejklock\Ci4Inertia\ScrollMetadata;
use Ejklock\Ci4Inertia\Ssr\BundleDetector;
use Ejklock\Ci4Inertia\Ssr\Gateway;
use Ejklock\Ci4Inertia\Ssr\HttpGateway;
use Ejklock\Ci4Inertia\Ssr\Response;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;

/**
 * Verifies the static `Inertia` class reaches the shared `inertia` service on every call,
 * answers what the factory answers, and keeps the same public surface as `ResponseFactory`.
 *
 * @internal
 */
final class InertiaTest extends CIUnitTestCase
{
    private int $ssrRequests = 0;

    protected function tearDown(): void
    {
        Services::reset(true);

        parent::tearDown();
    }

    private function makeRequest(array $headers = []): IncomingRequest
    {
        $config  = config(AppConfig::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'users'), null, new UserAgent());

        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }

        return $request;
    }

    private function injectFactory(array $headers = ['X-Inertia' => 'true'], string $version = 'v1'): ResponseFactory
    {
        $factory = new ResponseFactory($this->makeRequest($headers), 'app', static fn (): string => $version);

        Services::injectMock('inertia', $factory);

        return $factory;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageOf(InertiaResponse $response): array
    {
        $result = $response->toResponse($this->makeRequest(['X-Inertia' => 'true']), service('response', null, false));

        return json_decode((string) $result->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testRenderCarriesSharedAndPerRenderProps(): void
    {
        $this->injectFactory();

        Inertia::share('a', 'shared');
        $page = $this->pageOf(Inertia::render('C', ['b' => 2]));

        $this->assertSame('C', $page['component']);
        $this->assertSame(['a' => 'shared', 'b' => 2], $page['props']);
    }

    public function testShareTakesADotKeyAndAPropertiesProvider(): void
    {
        $this->injectFactory();

        Inertia::share('auth.user', 'Ada');
        Inertia::share(new class () implements ProvidesInertiaProperties {
            public function toInertiaProperties(RenderContext $context): iterable
            {
                return ['locale' => 'pt'];
            }
        });
        $page = $this->pageOf(Inertia::render('C'));

        $this->assertSame(['auth' => ['user' => 'Ada'], 'locale' => 'pt'], $page['props']);
        $this->assertSame(['auth', 'locale'], $page['sharedProps']);
    }

    public function testSharedPropsCanBeReadAndFlushedThroughTheFacade(): void
    {
        $this->injectFactory();

        Inertia::share(['auth' => ['user' => 'Ada']]);

        $this->assertSame('Ada', Inertia::getShared('auth.user'));
        $this->assertSame('x', Inertia::getShared('missing', 'x'));

        Inertia::flushShared();

        $this->assertSame([], Inertia::getShared());
    }

    public function testGetVersionReturnsTheResolvedVersion(): void
    {
        $this->injectFactory(version: 'v7');

        $this->assertSame('v7', Inertia::getVersion());
    }

    public function testOptionalIsLeftOutAndAlwaysIsSentOnAFullRender(): void
    {
        $this->injectFactory();

        $page = $this->pageOf(Inertia::render('C', [
            'lazy'   => Inertia::optional(static fn (): int => 1),
            'sticky' => Inertia::always('x'),
        ]));

        $this->assertSame(['sticky' => 'x'], $page['props']);
    }

    public function testComponentTransformerRootViewAndUrlResolverApplyToLaterRenders(): void
    {
        $this->injectFactory();

        Inertia::transformComponentUsing(static fn ($component): string => 'Pages/' . $component);
        Inertia::resolveUrlUsing(static fn (IncomingRequest $request): string => '/custom');
        Inertia::setRootView('Ejklock\Ci4InertiaTestSupport\Views\shell');

        $response = Inertia::render('Users');
        $page     = $this->pageOf($response);
        $html     = (string) $response->toResponse($this->makeRequest(), service('response', null, false))->getBody();

        $this->assertSame('Pages/Users', $page['component']);
        $this->assertSame('/custom', $page['url']);
        $this->assertStringContainsString('Inertia test shell', $html);
    }

    public function testLocationAcceptsARedirectResponse(): void
    {
        $this->injectFactory();

        $response = Inertia::location(redirect()->to('/y'));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringEndsWith('/y', $response->getHeaderLine('X-Inertia-Location'));
    }

    public function testShareOnceChainsOnTheSharedInstanceIntoTheRenderedPage(): void
    {
        $this->injectFactory();
        Time::setTestNow('2030-01-01 00:00:00', 'UTC');

        Inertia::shareOnce('plans', static fn (): int => 1)->as('catalog')->until(60);
        $page = $this->pageOf(Inertia::render('C'));

        Time::setTestNow();
        $this->assertSame('plans', $page['onceProps']['catalog']['prop']);
        $this->assertSame(
            (strtotime('2030-01-01 00:00:00 UTC') + 60) * 1000,
            $page['onceProps']['catalog']['expiresAt'],
        );
    }

    public function testOnceModifiersChangeTheInstanceTheFacadeReturned(): void
    {
        $this->injectFactory();

        $prop = Inertia::once(static fn (): int => 1);
        $prop->fresh();

        $this->assertTrue($prop->shouldBeRefreshed());
        $this->assertSame(1, $prop());
    }

    public function testDeferReturnsADeferPropInTheGivenGroup(): void
    {
        $this->injectFactory();

        $prop = Inertia::defer(static fn (): int => 5, 'side');

        $this->assertSame('side', $prop->group());
    }

    public function testDeferBuildsARescuingPropOnlyWhenAsked(): void
    {
        $this->injectFactory();

        $this->assertFalse(Inertia::defer(static fn (): int => 5)->shouldRescue());
        $this->assertTrue(Inertia::defer(static fn (): int => 5, 'default', true)->shouldRescue());
    }

    public function testScrollReturnsAScrollPropWithTheDataWrapperOrACustomOne(): void
    {
        $this->injectFactory();

        $default = Inertia::scroll(static fn (): array => ['data' => []], metadata: new ScrollMetadata('page', null, 2, 1));
        $custom  = Inertia::scroll([], 'items', new ScrollMetadata('page'));
        $page    = $this->pageOf(Inertia::render('C', ['u' => $default, 'v' => $custom]));

        $this->assertSame(['u.data', 'v.items'], $page['mergeProps']);
        $this->assertSame(2, $page['scrollProps']['u']['nextPage']);
    }

    public function testMergeAndDeepMergePropsAreListedByTheRender(): void
    {
        $this->injectFactory();

        $merge = Inertia::merge(['x']);
        $deep  = Inertia::deepMerge(['y']);
        $page  = $this->pageOf(Inertia::render('C', ['m' => $merge, 'd' => $deep]));

        $this->assertSame(['m'], $page['mergeProps']);
        $this->assertSame(['d'], $page['deepMergeProps']);
    }

    public function testVersionSetsThePageVersion(): void
    {
        $this->injectFactory();

        Inertia::version('v9');

        $this->assertSame('v9', $this->pageOf(Inertia::render('C'))['version']);
    }

    public function testLocationAnswers409OnAnInertiaRequest(): void
    {
        $this->injectFactory();

        $response = Inertia::location('/x');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('/x', $response->getHeaderLine('X-Inertia-Location'));
    }

    public function testEncryptHistoryAndClearHistoryShowInThePage(): void
    {
        $this->mockSession();
        $this->injectFactory();

        Inertia::encryptHistory();
        Inertia::clearHistory();
        $page = $this->pageOf(Inertia::render('C'));

        $this->assertTrue($page['encryptHistory']);
        $this->assertTrue($page['clearHistory']);
    }

    public function testFlashPreserveFragmentAndTheFlashReadersReachTheFactory(): void
    {
        $this->mockSession();
        $factory = $this->injectFactory();

        $this->assertSame($factory, Inertia::flash('toast', 'Saved'));
        $this->assertSame(['toast' => 'Saved'], Inertia::getFlashed());

        Inertia::preserveFragment();
        $page = $this->pageOf(Inertia::render('C'));

        $this->assertSame(['toast' => 'Saved'], $page['flash']);
        $this->assertTrue($page['preserveFragment']);
        $this->assertSame([], Inertia::pullFlashed());
    }

    public function testResponseFlashReachesThePageThroughTheFacade(): void
    {
        $this->mockSession();
        $this->injectFactory();

        $response = Inertia::render('C');

        $this->assertSame($response, $response->flash(['k' => 'v']));
        $this->assertSame(['k' => 'v'], $this->pageOf($response)['flash']);
    }

    public function testBackRedirectsThroughTheFacade(): void
    {
        $factory = new ResponseFactory($this->makeRequest(['Referer' => 'https://example.com/a']), 'app', static fn (): string => 'v1');
        Services::injectMock('inertia', $factory);

        $response = Inertia::back(303, ['X-A' => '1'], '/home');

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('https://example.com/a', $response->getHeaderLine('Location'));
        $this->assertSame('1', $response->getHeaderLine('X-A'));
    }

    public function testCallsReachTheFactoryInjectedAfterAReset(): void
    {
        $this->injectFactory(version: 'first');
        $this->assertSame('first', $this->pageOf(Inertia::render('C'))['version']);

        Services::reset(true);
        $this->injectFactory(version: 'second');

        $this->assertSame('second', $this->pageOf(Inertia::render('C'))['version']);
    }

    private function handleAnException(): void
    {
        ob_start();

        try {
            (new InertiaExceptionHandler(config('Exceptions')))->handle(new RuntimeException('x'), $this->makeRequest(), service('response', null, false), 500, 1);
        } finally {
            ob_end_clean();
        }
    }

    public function testHandleExceptionsUsingRegistersTheCallbackTheExceptionHandlerRuns(): void
    {
        /** @var ArrayObject<int, string> $seen */
        $seen = new ArrayObject();

        try {
            Inertia::handleExceptionsUsing(static function (ExceptionResponse $r) use ($seen) {
                $seen->append('first');

                return $r;
            });
            Inertia::handleExceptionsUsing(static function (ExceptionResponse $r) use ($seen) {
                $seen->append('second');

                return $r;
            });

            $this->handleAnException();

            $this->assertSame(['second'], $seen->getArrayCopy());

            InertiaExceptionHandler::respondUsing(null);
            $this->handleAnException();

            $this->assertSame(['second'], $seen->getArrayCopy());
        } finally {
            InertiaExceptionHandler::respondUsing(null);
        }
    }

    public function testTheClassCannotBeInstantiated(): void
    {
        $constructor = (new ReflectionClass(Inertia::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    public function testStaticSurfaceMatchesTheFactoryPublicMethods(): void
    {
        $this->assertSurfaceMatches(ResponseFactory::class, Inertia::class);
    }

    public function testSurfaceCheckNamesAMethodTheStaticClassLacks(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('extraMethod');

        $this->assertSurfaceMatches(FactoryWithExtraMethod::class, Inertia::class);
    }

    /**
     * @param class-string $factory
     * @param class-string $static
     */
    private function assertSurfaceMatches(string $factory, string $static): void
    {
        $expected = $this->methodsByName(
            array_filter(
                (new ReflectionClass($factory))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => ! $method->isConstructor(),
            ),
        );
        $actual = $this->methodsByName(
            array_filter(
                (new ReflectionClass($static))->getMethods(),
                static fn (ReflectionMethod $method): bool => $method->isPublic() && $method->isStatic() && $method->getName() !== 'handleExceptionsUsing',
            ),
        );

        foreach (array_keys($expected) as $name) {
            $this->assertArrayHasKey($name, $actual, "{$static} lacks the static method {$name}()");
        }

        foreach (array_keys($actual) as $name) {
            $this->assertArrayHasKey($name, $expected, "{$static}::{$name}() has no counterpart in {$factory}");
        }

        foreach ($expected as $name => $method) {
            $this->assertSame($this->signatureOf($method), $this->signatureOf($actual[$name]), "{$name}() signature differs");
        }
    }

    /**
     * @param iterable<ReflectionMethod> $methods
     *
     * @return array<string, ReflectionMethod>
     */
    private function methodsByName(iterable $methods): array
    {
        $byName = [];

        foreach ($methods as $method) {
            $byName[$method->getName()] = $method;
        }

        return $byName;
    }

    /**
     * @return array{params: list<array{string, string, bool, string, bool}>, return: string}
     */
    private function signatureOf(ReflectionMethod $method): array
    {
        return [
            'params' => array_map(
                static fn (ReflectionParameter $parameter): array => [
                    $parameter->getName(),
                    (string) $parameter->getType(),
                    $parameter->isDefaultValueAvailable(),
                    $parameter->isDefaultValueAvailable() ? var_export($parameter->getDefaultValue(), true) : '',
                    $parameter->isVariadic(),
                ],
                $method->getParameters(),
            ),
            'return' => (string) $method->getReturnType(),
        ];
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

    private function injectPlainGateway(): void
    {
        BaseService::injectMock('inertiaSsrGateway', new class () implements Gateway {
            public function dispatch(array $page): ?Response
            {
                return null;
            }
        });
    }

    public function testDisableSsrWithoutAnArgumentDisablesSsr(): void
    {
        $gateway = $this->injectSsrGateway();

        Inertia::disableSsr();

        $this->dispatchSsr($gateway, 'users');
        $this->assertSame(0, $this->ssrRequests);
    }

    public function testDisableSsrWithAFalseConditionKeepsSsrOn(): void
    {
        $gateway = $this->injectSsrGateway();

        Inertia::disableSsr(false);

        $this->dispatchSsr($gateway, 'users');
        $this->assertSame(1, $this->ssrRequests);
    }

    public function testWithoutSsrExcludesPathsFromTheSharedGateway(): void
    {
        $gateway = $this->injectSsrGateway();

        Inertia::withoutSsr('admin/*');

        $this->dispatchSsr($gateway, 'admin/x');
        $this->assertSame(0, $this->ssrRequests);
        $this->dispatchSsr($gateway, 'users');
        $this->assertSame(1, $this->ssrRequests);
    }

    public function testConfigureSsrRequestUsingReachesTheSharedGateway(): void
    {
        $gateway = $this->injectSsrGateway();
        /** @var ArrayObject<int, int> $calls */
        $calls = new ArrayObject();

        Inertia::configureSsrRequestUsing(static function () use ($calls): ?CURLRequest {
            $calls->append(1);

            return null;
        });

        $this->dispatchSsr($gateway, 'users');
        $this->assertCount(1, $calls);
    }

    public function testTheSsrControlsRefuseAGatewayThatSupportsNone(): void
    {
        $this->injectPlainGateway();

        foreach ([
            [static fn () => Inertia::disableSsr(), 'The configured SSR gateway does not support disabling server-side rendering conditionally.'],
            [static fn () => Inertia::withoutSsr('a'), 'The configured SSR gateway does not support excluding paths from server-side rendering.'],
            [static fn () => Inertia::configureSsrRequestUsing(), 'The configured SSR gateway does not support configuring server-side rendering requests.'],
        ] as [$call, $message]) {
            try {
                $call();
                $this->fail('Expected a LogicException.');
            } catch (LogicException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }
}

interface FactoryWithExtraMethod
{
    public function render(string $component, array $props = []): InertiaResponse;

    public function extraMethod(): void;
}
