<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use Closure;
use CodeIgniter\Config\BaseService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Ejklock\Ci4Inertia\AlwaysProp;
use Ejklock\Ci4Inertia\Filters\InertiaFilter;
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

    private Closure $originalVersion;
    private string $originalRootView;

    /**
     * @var array<string, string>
     */
    private array $originalAliases;

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

        $this->resetPerRequestServices();
    }

    protected function tearDown(): void
    {
        $inertia           = config('Inertia');
        $inertia->version  = $this->originalVersion;
        $inertia->rootView = $this->originalRootView;

        config('Filters')->aliases = $this->originalAliases;

        $this->resetPerRequestServices();

        parent::tearDown();
    }

    /**
     * Drops the per-request singletons a simulated request leaves behind, so the next
     * simulated request builds its own response, routes, router and `inertia` service.
     */
    private function resetPerRequestServices(): void
    {
        BaseService::resetSingle('response');
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

    #[DataProvider('provideDestructiveMethodRedirectIsConvertedFrom302To303')]
    public function testDestructiveMethodRedirectIsConvertedFrom302To303(string $method): void
    {
        $this->withRoutes([
            [$method, 'page/1', static fn () => redirect()->to('/page/1', 302), ['filter' => 'inertia']],
        ]);

        $result = $this->call($method, 'page/1');

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

    public function testPostRedirectStaysAt302(): void
    {
        $this->withRoutes([
            ['POST', 'page/1', static fn () => redirect()->to('/page/1', 302), ['filter' => 'inertia']],
        ]);

        $result = $this->post('page/1');

        $result->assertStatus(302);
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
            ['errors' => ['subject' => 'Subject is required.']],
            ['subject' => 'Subject is required.'],
        ];

        yield 'only the withInput validation errors' => [
            ['_ci_validation_errors' => ['email' => 'Email is invalid.']],
            ['email' => 'Email is invalid.'],
        ];

        yield 'both sources: the errors flash wins whole' => [
            [
                'errors'                => ['subject' => 'Subject is required.'],
                '_ci_validation_errors' => ['email' => 'Email is invalid.'],
            ],
            ['subject' => 'Subject is required.'],
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
}
