<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Testing;

use Closure;
use CodeIgniter\Test\CIUnitTestCase;
use Ejklock\Ci4Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Verifies `AssertableInertia`: page-level assertions, dot-path presence and value
 * assertions, strict comparison, and fluent chaining that stops at the first failure.
 *
 * @internal
 */
final class AssertableInertiaTest extends CIUnitTestCase
{
    /**
     * @var array{bool, list<string>, list<string>}
     */
    private array $originalPageConfig = [true, [], []];

    protected function setUp(): void
    {
        parent::setUp();

        $inertia                  = config('Inertia');
        $this->originalPageConfig = [$inertia->ensurePagesExistInTests, $inertia->pagePaths, $inertia->pageExtensions];
        $inertia->pagePaths       = [__DIR__ . '/../_support/pages'];
    }

    protected function tearDown(): void
    {
        $inertia                                                                           = config('Inertia');
        [$inertia->ensurePagesExistInTests, $inertia->pagePaths, $inertia->pageExtensions] = $this->originalPageConfig;

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function page(): array
    {
        return [
            'component' => 'Users/Index',
            'props'     => [
                'title'   => 'Users',
                'count'   => 2,
                'nothing' => null,
                'users'   => [['name' => 'Ada'], ['name' => 'Linus']],
            ],
            'url'     => '/users',
            'version' => 'v1',
        ];
    }

    private function assertable(): AssertableInertia
    {
        return AssertableInertia::fromPage($this->page());
    }

    /**
     * @param Closure(AssertableInertia): AssertableInertia $assertions
     */
    private function assertPasses(Closure $assertions): void
    {
        $page = $this->assertable();

        $this->assertSame($page, $assertions($page));
    }

    private function expectFailure(string ...$messageParts): void
    {
        $this->expectException(AssertionFailedError::class);

        foreach ($messageParts as $part) {
            $this->expectExceptionMessage($part);
        }
    }

    #[DataProvider('provideFromPageRejectsPageMissingRequiredKey')]
    public function testFromPageRejectsPageMissingRequiredKey(string $key): void
    {
        $page = $this->page();
        unset($page[$key]);

        $this->expectFailure($key);

        AssertableInertia::fromPage($page);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideFromPageRejectsPageMissingRequiredKey(): iterable
    {
        yield 'component' => ['component'];

        yield 'props' => ['props'];

        yield 'url' => ['url'];

        yield 'version' => ['version'];
    }

    public function testPageExposesUnknownKeysUntouched(): void
    {
        $page                  = $this->page();
        $page['deferredProps'] = ['default' => ['stats']];

        $this->assertSame($page, AssertableInertia::fromPage($page)->page());
    }

    public function testComponentUrlAndVersionPassWhenEqual(): void
    {
        $assertable = $this->assertable();

        $this->assertSame($assertable, $assertable->component('Users/Index')->url('/users')->version('v1'));
    }

    public function testComponentFailsWhenThePageFileIsMissing(): void
    {
        $this->expectFailure('Inertia page component file [Missing/Page] does not exist.');

        AssertableInertia::fromPage(['component' => 'Missing/Page'] + $this->page())->component('Missing/Page');
    }

    public function testComponentSkipsTheFileCheckWhenAskedTo(): void
    {
        $page = AssertableInertia::fromPage(['component' => 'Missing/Page'] + $this->page());

        $this->assertSame($page, $page->component('Missing/Page', false));
    }

    public function testComponentChecksTheFileWhenAskedToEvenIfTheConfigTurnsItOff(): void
    {
        config('Inertia')->ensurePagesExistInTests = false;

        $this->expectFailure('Inertia page component file [Missing/Page] does not exist.');

        AssertableInertia::fromPage(['component' => 'Missing/Page'] + $this->page())->component('Missing/Page', true);
    }

    public function testComponentFollowsTheConfigWhenNotToldWhetherToCheck(): void
    {
        config('Inertia')->ensurePagesExistInTests = false;
        $page                                      = AssertableInertia::fromPage(['component' => 'Missing/Page'] + $this->page());

        $this->assertSame($page, $page->component('Missing/Page'));
    }

    public function testComponentFailsOnTheNameBeforeLookingForTheFile(): void
    {
        $this->expectFailure('Missing/Page', 'Users/Index');

        $this->assertable()->component('Missing/Page', true);
    }

    public function testComponentFailureNamesBothComponents(): void
    {
        $this->expectFailure('Posts/Index', 'Users/Index');

        $this->assertable()->component('Posts/Index');
    }

    public function testUrlFailureNamesBothUrls(): void
    {
        $this->expectFailure('/posts', '/users');

        $this->assertable()->url('/posts');
    }

    public function testVersionFailureNamesBothVersions(): void
    {
        $this->expectFailure('v2', 'v1');

        $this->assertable()->version('v2');
    }

    public function testHasPassesOnPresentKeyAndNestedListIndex(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->has('users')->has('users.0.name'));
    }

    public function testHasPassesWhenValueIsNull(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->has('nothing'));
    }

    public function testHasFailsOnAbsentPath(): void
    {
        $this->expectFailure('users.5');

        $this->assertable()->has('users.5');
    }

    public function testHasFailsOnPathThroughScalar(): void
    {
        $this->expectFailure('title.length');

        $this->assertable()->has('title.length');
    }

    public function testHasWithCountPassesOnMatchingList(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->has('users', 2));
    }

    public function testHasWithCountFailureNamesPathExpectedAndActual(): void
    {
        $this->expectFailure('users', '3', '2');

        $this->assertable()->has('users', 3);
    }

    public function testMissingPassesOnAbsentPathAndPathThroughScalar(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->missing('users.5')->missing('title.length'));
    }

    public function testMissingFailsOnPresentKey(): void
    {
        $this->expectFailure('title');

        $this->assertable()->missing('title');
    }

    public function testMissingFailsOnPresentNullValue(): void
    {
        $this->expectFailure('nothing');

        $this->assertable()->missing('nothing');
    }

    public function testWherePassesOnStrictlyEqualValue(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->where('title', 'Users')->where('count', 2)->where('nothing', null));
    }

    public function testWhereFailsOnLooselyEqualValueOfAnotherType(): void
    {
        $this->expectFailure('count');

        $this->assertable()->where('count', '2');
    }

    public function testWhereFailsOnAbsentPath(): void
    {
        $this->expectFailure('users.9.name');

        $this->assertable()->where('users.9.name', 'Ada');
    }

    public function testWhereClosureReceivesValueAndPassesOnTrue(): void
    {
        $received = null;

        $this->assertable()->where('title', static function (mixed $value) use (&$received): bool {
            $received = $value;

            return true;
        });

        $this->assertSame('Users', $received);
    }

    public function testWhereClosureFailsOnFalseNamingPath(): void
    {
        $this->expectFailure('title');

        $this->assertable()->where('title', static fn (mixed $value): bool => false);
    }

    public function testWhereClosureFailsOnTruthyNonTrueReturn(): void
    {
        $this->expectFailure('title');

        $this->assertable()->where('title', static fn (mixed $value): int => 1);
    }

    public function testCountPassesOnMatchingArray(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->count('users', 2));
    }

    public function testCountFailureNamesExpectedAndActual(): void
    {
        $this->expectFailure('users', '5', '2');

        $this->assertable()->count('users', 5);
    }

    public function testCountFailsOnNonArrayValue(): void
    {
        $this->expectFailure('title');

        $this->assertable()->count('title', 1);
    }

    public function testCountFailsOnAbsentPath(): void
    {
        $this->expectFailure('absent');

        $this->assertable()->count('absent', 0);
    }

    public function testPropReturnsValueAtPath(): void
    {
        $this->assertSame('Linus', $this->assertable()->prop('users.1.name'));
        $this->assertNull($this->assertable()->prop('nothing'));
    }

    public function testPropFailsOnAbsentPath(): void
    {
        $this->expectFailure('users.5');

        $this->assertable()->prop('users.5');
    }

    public function testChainStopsAtFirstFailure(): void
    {
        $reached = false;

        try {
            $this->assertable()
                ->component('Posts/Index')
                ->where('title', static function () use (&$reached): bool {
                    $reached = true;

                    return true;
                });
            $this->fail('the chain should have failed on the component');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('Posts/Index', $e->getMessage());
        }

        $this->assertFalse($reached);
    }

    public function testHasAllAcceptsAListOrVariadicPaths(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->hasAll(['title', 'users.0.name']));
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->hasAll('title', 'users.1.name'));
    }

    public function testHasAllFailsNamingTheFirstMissingPath(): void
    {
        $this->expectFailure('[nope.first]');

        $this->assertable()->hasAll(['title', 'nope.first', 'nope.second']);
    }

    public function testMissingAllAcceptsAListOrVariadicPaths(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->missingAll(['absent', 'users.5']));
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->missingAll('absent', 'users.5.name'));
    }

    public function testMissingAllFailsNamingTheFirstPresentPath(): void
    {
        $this->expectFailure('[count]');

        $this->assertable()->missingAll(['absent', 'count', 'title']);
    }

    public function testReloadWithoutARequestHandleFailsSayingFeatureTestTraitIsRequired(): void
    {
        $this->expectFailure('FeatureTestTrait');

        $this->assertable()->reload();
    }

    /**
     * @param array<string, mixed> $override
     */
    private function reloadingPage(array $override): AssertableInertia
    {
        $reloaded = $override + $this->page();

        return AssertableInertia::fromPageWithReloader($this->page(), static fn (): array => $reloaded);
    }

    public function testReloadComparesTheComponentWithoutLookingForTheFile(): void
    {
        $page     = ['component' => 'Missing/Page'] + $this->page();
        $reloaded = AssertableInertia::fromPageWithReloader($page, static fn (): array => $page);

        $this->assertSame($reloaded, $reloaded->reload());
    }

    public function testFromPageTakesOnlyThePage(): void
    {
        $this->assertSame(1, (new ReflectionMethod(AssertableInertia::class, 'fromPage'))->getNumberOfParameters());
    }

    public function testReloadFailsNamingTheUrlWhenTheReloadedPageHasAnotherUrl(): void
    {
        $this->expectFailure('/elsewhere');

        $this->reloadingPage(['url' => '/elsewhere'])->reload();
    }

    public function testReloadFailsNamingTheVersionWhenTheReloadedPageHasAnotherVersion(): void
    {
        $this->expectFailure('v2');

        $this->reloadingPage(['version' => 'v2'])->reload();
    }

    public function testReloadRequestsThePageUrlWithTheVersionAsAString(): void
    {
        $seen = [];
        $page = AssertableInertia::fromPageWithReloader(['version' => 7] + $this->page(), static function (string $url, array $headers) use (&$seen): array {
            $seen = [$url, $headers];

            return ['version' => '7'] + ['component' => 'Users/Index', 'props' => [], 'url' => '/users'];
        });

        $page->reload(only: 'title');

        $this->assertSame(['/users', ['X-Inertia-Version' => '7', 'X-Inertia-Partial-Component' => 'Users/Index', 'X-Inertia-Partial-Data' => 'title']], $seen);
    }

    public function testLoadDeferredPropsWithNothingToLoadFailsInsteadOfReloadingEverything(): void
    {
        $this->expectFailure('deferred');

        $this->assertable()->loadDeferredProps(static fn (AssertableInertia $page): null => null);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageWithFlash(): array
    {
        return [...$this->page(), 'flash' => ['toast' => 'Saved', 'a' => ['b' => null], 'zero' => 0]];
    }

    public function testHasFlashPassesForAPresentKeyAValueAndADotPath(): void
    {
        $page = AssertableInertia::fromPage($this->pageWithFlash());

        $this->assertSame($page, $page->hasFlash('toast')->hasFlash('toast', 'Saved')->hasFlash('a.b')->hasFlash('a.b', null)->hasFlash('zero', 0));
    }

    public function testHasFlashFailsForAMissingKey(): void
    {
        $this->expectFailure('Inertia Flash Data is missing key [x].');

        AssertableInertia::fromPage($this->pageWithFlash())->hasFlash('x');
    }

    public function testHasFlashFailsForAWrongValue(): void
    {
        $this->expectFailure('Inertia Flash Data [toast] does not match expected value.');

        AssertableInertia::fromPage($this->pageWithFlash())->hasFlash('toast', 'Other');
    }

    public function testHasFlashComparesStrictly(): void
    {
        $this->expectFailure('Inertia Flash Data [zero] does not match expected value.');

        AssertableInertia::fromPage($this->pageWithFlash())->hasFlash('zero', '0');
    }

    public function testMissingFlashPassesWhenAbsentAndFailsWhenPresent(): void
    {
        $page = AssertableInertia::fromPage($this->pageWithFlash());

        $this->assertSame($page, $page->missingFlash('x')->missingFlash('a.c'));

        $this->expectFailure('Inertia Flash Data has unexpected key [toast].');

        $page->missingFlash('toast');
    }

    public function testFlashAssertionsTryTheLiteralDottedKeyBeforeTheDotPath(): void
    {
        $page = AssertableInertia::fromPage([...$this->page(), 'flash' => ['form.saved' => true]]);

        $this->assertSame($page, $page->hasFlash('form.saved', true));

        $this->expectFailure('Inertia Flash Data has unexpected key [form.saved].');

        $page->missingFlash('form.saved');
    }

    public function testPropAssertionsTryTheLiteralDottedKeyBeforeTheDotPath(): void
    {
        $page = $this->page();

        $page['props']['a.b'] = 'literal';
        $page['props']['a']   = ['b' => 'nested'];

        $assertable = AssertableInertia::fromPage($page);

        $this->assertSame($assertable, $assertable->where('a.b', 'literal'));
    }

    public function testMissingFlashFailsForAPresentKeyWhoseValueIsNull(): void
    {
        $this->expectFailure('Inertia Flash Data has unexpected key [a.b].');

        AssertableInertia::fromPage($this->pageWithFlash())->missingFlash('a.b');
    }

    public function testAPageWithoutFlashHasEmptyFlashData(): void
    {
        $page = $this->assertable();

        $this->assertSame($page, $page->missingFlash('toast'));

        $this->expectFailure('Inertia Flash Data is missing key [toast].');

        $page->hasFlash('toast');
    }

    public function testAnOptedInPageHasItsBigIntegerMarkersDecodedInPropsAndFlash(): void
    {
        $page = AssertableInertia::fromPage([
            ...$this->page(),
            'props'               => ['title' => 'Users', 'id' => ['$bigint' => '9007199254740993'], 'rows' => [['id' => ['$bigint' => '-9223372036854775808']]]],
            'flash'               => ['total' => ['$bigint' => (string) PHP_INT_MAX]],
            'preserveBigIntegers' => true,
        ]);

        $page->where('id', 9007199254740993)->where('rows.0.id', PHP_INT_MIN)->hasFlash('total', PHP_INT_MAX);

        $this->assertSame(9007199254740993, $page->prop('id'));
    }

    public function testAMarkerOnAPageWithoutTheFlagIsLeftAsTheArray(): void
    {
        $page = AssertableInertia::fromPage([...$this->page(), 'props' => ['id' => ['$bigint' => '5']], 'flash' => ['id' => ['$bigint' => '5']]]);

        $page->where('id', ['$bigint' => '5'])->hasFlash('id', ['$bigint' => '5']);

        $this->assertSame(['$bigint' => '5'], $page->prop('id'));
    }

    public function testAMarkerWhoseValueIsNotAStringIsLeftAsTheArrayOnAnOptedInPage(): void
    {
        $page = AssertableInertia::fromPage([
            ...$this->page(),
            'props'               => ['id' => ['$bigint' => 5], 'other' => ['$bigint' => ['x']]],
            'preserveBigIntegers' => true,
        ]);

        $this->assertSame(['$bigint' => 5], $page->prop('id'));
        $this->assertSame(['$bigint' => ['x']], $page->prop('other'));
    }
}
