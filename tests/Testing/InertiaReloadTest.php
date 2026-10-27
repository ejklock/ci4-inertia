<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Testing;

use Closure;
use CodeIgniter\Config\BaseService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Ejklock\Ci4Inertia\AlwaysProp;
use Ejklock\Ci4Inertia\DeferProp;
use Ejklock\Ci4Inertia\Support\Header;
use Ejklock\Ci4Inertia\Testing\AssertableInertia;
use Ejklock\Ci4Inertia\Testing\InertiaTestTrait;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Verifies following an Inertia page from a feature test: `reload()` re-requests the page
 * with the version and partial headers, `reloadOnly()` / `reloadExcept()` assert what the
 * partial reload returned, and `loadDeferredProps()` loads the page's deferred groups.
 *
 * @internal
 */
final class InertiaReloadTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use InertiaTestTrait;

    private const VERSION = 'reload-test-version';

    /**
     * Headers of each request the route served, in order.
     *
     * @var list<array<string, string>>
     */
    private array $served = [];

    private int $deferredCalls = 0;
    private Closure $originalVersion;
    private string $originalRootView;

    protected function setUp(): void
    {
        parent::setUp();

        $inertia                = config('Inertia');
        $this->originalVersion  = $inertia->version;
        $this->originalRootView = $inertia->rootView;
        $inertia->version       = static fn (): string => self::VERSION;
        $inertia->rootView      = 'Ejklock\Ci4InertiaTestSupport\Views\shell';

        $this->served        = [];
        $this->deferredCalls = 0;
        $this->resetPerRequestServices();
    }

    protected function tearDown(): void
    {
        $inertia           = config('Inertia');
        $inertia->version  = $this->originalVersion;
        $inertia->rootView = $this->originalRootView;

        $this->resetPerRequestServices();

        parent::tearDown();
    }

    private function resetPerRequestServices(): void
    {
        BaseService::resetSingle('response');
        BaseService::resetSingle('inertia');
        BaseService::resetSingle('routes');
        BaseService::resetSingle('router');
    }

    /**
     * Serves `GET /page`, rendering the props `$props` builds; the route records the request
     * headers it saw.
     *
     * @param Closure(): array<string, mixed> $props
     * @param (Closure(int): string)|null     $componentFor the component to render on the nth request (0-based)
     */
    private function servePage(Closure $props, ?Closure $componentFor = null): void
    {
        $this->withRoutes([['GET', 'page', function () use ($props, $componentFor) {
            $request        = service('request');
            $this->served[] = [
                'inertia'   => $request->getHeaderLine(Header::INERTIA),
                'version'   => $request->getHeaderLine(Header::VERSION),
                'component' => $request->getHeaderLine(Header::PARTIAL_COMPONENT),
                'only'      => $request->getHeaderLine(Header::PARTIAL_ONLY),
                'except'    => $request->getHeaderLine(Header::PARTIAL_EXCEPT),
                'reset'     => $request->getHeaderLine(Header::RESET),
                'custom'    => $request->getHeaderLine('X-Custom'),
            ];
            $component = $componentFor instanceof Closure ? $componentFor(count($this->served) - 1) : 'Page/Index';

            return service('inertia')->render($component, $props())->toResponse($request, service('response'));
        }]]);
    }

    private function servePlainProps(): void
    {
        $this->servePage(static fn (): array => ['a' => 1, 'b' => 2]);
    }

    private function serveDeferredProps(): void
    {
        $this->servePage(function (): array {
            $load = fn (string $value): Closure => function () use ($value): string {
                $this->deferredCalls++;

                return $value;
            };

            return [
                'x' => new DeferProp($load('X')),
                'y' => new DeferProp($load('Y'), 'sidebar'),
                'z' => new DeferProp($load('Z'), 'sidebar'),
            ];
        });
    }

    public function testReloadHandsTheCallbackAPageWithTheSameComponentUrlAndVersion(): void
    {
        $this->servePlainProps();
        $seen = null;

        $this->assertInertia($this->withHeaders($this->inertiaHeaders())->get('/page?tab=1&sort=asc'))
            ->reload(static function (AssertableInertia $page) use (&$seen): void {
                $seen = $page->page();
            });

        $this->assertCount(2, $this->served);
        $this->assertSame('Page/Index', $seen['component']);
        $this->assertSame('/page?tab=1&sort=asc', $seen['url']);
        $this->assertSame(self::VERSION, $seen['version']);
        $this->assertSame(['a' => 1, 'b' => 2], $seen['props']);
        $this->assertSame(['inertia' => 'true', 'version' => self::VERSION, 'component' => '', 'only' => '', 'except' => '', 'reset' => '', 'custom' => ''], $this->served[1]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function visitPartially(array $headers): AssertableInertia
    {
        $this->withHeaders($headers + ['X-Custom' => 'kept']);

        return $this->assertInertia($this->get('/page'));
    }

    #[DataProvider('providePartialHeaderCasings')]
    public function testReloadDropsThePartialHeadersOfTheEarlierVisit(array $headers): void
    {
        $this->servePlainProps();
        $page = $this->visitPartially($headers);

        $page->reload();

        $this->assertSame(['component' => '', 'only' => '', 'except' => '', 'reset' => '', 'custom' => 'kept'], array_diff_key($this->served[1], ['inertia' => 1, 'version' => 1]));
    }

    #[DataProvider('providePartialHeaderCasings')]
    public function testReloadExceptSendsOnlyPartialExceptAfterAPartialVisit(array $headers): void
    {
        $this->servePlainProps();
        $page = $this->visitPartially($headers);

        $page->reloadExcept('a');

        $this->assertSame('a', $this->served[1]['except']);
        $this->assertSame('', $this->served[1]['only']);
        $this->assertSame('', $this->served[1]['reset']);
    }

    #[DataProvider('providePartialHeaderCasings')]
    public function testReloadOnlySendsOnlyPartialDataAfterAPartialVisit(array $headers): void
    {
        $this->servePlainProps();
        $page = $this->visitPartially($headers);

        $page->reloadOnly('b');

        $this->assertSame('b', $this->served[1]['only']);
        $this->assertSame('', $this->served[1]['except']);
        $this->assertSame('kept', $this->served[1]['custom']);
        $this->assertSame($headers + ['X-Custom' => 'kept'], $this->headers);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function providePartialHeaderCasings(): iterable
    {
        yield 'canonical case' => [[Header::INERTIA => 'true', Header::PARTIAL_COMPONENT => 'Page/Index', Header::PARTIAL_ONLY => 'a', Header::RESET => 'a']];

        yield 'lower case' => [['x-inertia' => 'true', 'x-inertia-partial-component' => 'Page/Index', 'x-inertia-partial-data' => 'a', 'x-inertia-reset' => 'a']];
    }

    public function testHeadersAreRestoredWhenTheReloadRequestThrows(): void
    {
        $headers = [Header::INERTIA => 'true', Header::PARTIAL_COMPONENT => 'Page/Index', Header::PARTIAL_ONLY => 'a', 'X-Custom' => 'kept'];
        $this->servePage(function (): array {
            if (count($this->served) > 1) {
                throw new RuntimeException('the second request fails');
            }

            return ['a' => 1];
        });
        $this->withHeaders($headers);
        $page = $this->assertInertia($this->get('/page'));

        try {
            $page->reload();
            $this->fail('the reload should have thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('the second request fails', $e->getMessage());
        }

        $this->assertSame($headers, $this->headers);
    }

    public function testReloadReturnsTheOriginalPageSoChainingContinuesOnIt(): void
    {
        $this->servePlainProps();
        $original = $this->assertInertia($this->get('/page'));

        $this->assertSame($original, $original->reload());
    }

    public function testReloadOnlySendsThePartialComponentAndPartialData(): void
    {
        $this->servePlainProps();

        $this->assertInertia($this->get('/page'))->reload(only: ['a']);

        $this->assertSame('Page/Index', $this->served[1]['component']);
        $this->assertSame('a', $this->served[1]['only']);
        $this->assertSame('', $this->served[1]['except']);
    }

    public function testReloadExceptSendsThePartialComponentAndPartialExcept(): void
    {
        $this->servePlainProps();

        $this->assertInertia($this->get('/page'))->reload(except: 'b,c');

        $this->assertSame('Page/Index', $this->served[1]['component']);
        $this->assertSame('b,c', $this->served[1]['except']);
        $this->assertSame('', $this->served[1]['only']);
    }

    public function testReloadFailsNamingTheComponentWhenTheRouteRendersAnotherOne(): void
    {
        $this->servePage(static fn (): array => [], static fn (int $request): string => $request === 0 ? 'Page/Index' : 'Page/Other');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Page/Other');

        $this->assertInertia($this->get('/page'))->reload();
    }

    public function testHeadersSetBeforeTheReloadAreSentAndRestoredAfterIt(): void
    {
        $this->servePlainProps();
        $this->withHeaders(['X-Custom' => 'kept']);
        $page = $this->assertInertia($this->get('/page'));

        $page->reload(only: 'a');

        $this->assertSame('kept', $this->served[1]['custom']);
        $this->assertSame(['X-Custom' => 'kept'], $this->headers);
    }

    public function testReloadOnlyPassesWhenTheRequestedPropsComeBackAndTheOthersDoNot(): void
    {
        $this->servePlainProps();
        $seen = null;

        $this->assertInertia($this->get('/page'))->reloadOnly('a', static function (AssertableInertia $page) use (&$seen): void {
            $seen = $page->page()['props'];
            $page->missing('b');
        });

        $this->assertSame(['a' => 1], $seen);
    }

    public function testReloadOnlyFailsNamingARequestedPropThatIsNotPresent(): void
    {
        $this->servePlainProps();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('[x]');

        $this->assertInertia($this->get('/page'))->reloadOnly(['a', 'x']);
    }

    public function testReloadExceptPassesWhenTheExceptedPropsAreGone(): void
    {
        $this->servePlainProps();
        $seen = null;

        $this->assertInertia($this->get('/page'))->reloadExcept('a', static function (AssertableInertia $page) use (&$seen): void {
            $seen = $page->page()['props'];
        });

        $this->assertSame(['b' => 2], $seen);
    }

    public function testReloadExceptFailsWhenTheExceptedPropIsAnAlwaysProp(): void
    {
        $this->servePage(static fn (): array => ['a' => new AlwaysProp(static fn (): int => 1), 'b' => 2]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('[a]');

        $this->assertInertia($this->get('/page'))->reloadExcept('a');
    }

    public function testLoadDeferredPropsWithOnlyACallbackLoadsEveryGroupInOneRequest(): void
    {
        $this->serveDeferredProps();
        $seen = null;

        $this->assertInertia($this->get('/page'))->loadDeferredProps(static function (AssertableInertia $page) use (&$seen): void {
            $seen = $page->page()['props'];
        });

        $this->assertCount(2, $this->served);
        $this->assertSame('x,y,z', $this->served[1]['only']);
        $this->assertSame(['x' => 'X', 'y' => 'Y', 'z' => 'Z'], $seen);
    }

    public function testLoadDeferredPropsOfOneGroupRequestsOnlyThatGroupsKeys(): void
    {
        $this->serveDeferredProps();
        $seen = null;

        $this->assertInertia($this->get('/page'))->loadDeferredProps('sidebar', static function (AssertableInertia $page) use (&$seen): void {
            $seen = $page->page()['props'];
        });

        $this->assertSame('y,z', $this->served[1]['only']);
        $this->assertSame(['y' => 'Y', 'z' => 'Z'], $seen);
        $this->assertSame(2, $this->deferredCalls);
    }

    public function testLoadDeferredPropsOfSeveralGroupsRequestsAllTheirKeys(): void
    {
        $this->serveDeferredProps();

        $this->assertInertia($this->get('/page'))->loadDeferredProps(['default', 'sidebar']);

        $this->assertSame('x,y,z', $this->served[1]['only']);
        $this->assertSame(3, $this->deferredCalls);
    }

    public function testLoadDeferredPropsSkipsAnUnknownGroupAndFailsWhenNoGroupHasKeys(): void
    {
        $this->serveDeferredProps();
        $page = $this->assertInertia($this->get('/page'));

        $page->loadDeferredProps(['nope', 'default']);
        $this->assertSame('x', $this->served[1]['only']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('nope');

        $page->loadDeferredProps('nope');
    }
}
