<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use ArrayObject;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App as AppConfig;
use Ejklock\Ci4Inertia\DeferProp;
use Ejklock\Ci4Inertia\InertiaResponse;
use Ejklock\Ci4Inertia\MergeProp;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionParameter;

/**
 * Verifies `MergeProp`: its resolution, its immutable mode switching, and the merge lists
 * the page object carries for it, including how `X-Inertia-Reset` and a partial reload
 * narrow those lists.
 *
 * @internal
 */
final class MergePropTest extends CIUnitTestCase
{
    /**
     * @param array<array-key, mixed> $props
     * @param array<string, string>   $headers
     *
     * @return array<string, mixed>
     */
    private function renderPage(array $props, array $headers = []): array
    {
        $config  = config(AppConfig::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'feed'), null, new UserAgent());

        foreach (['X-Inertia' => 'true', ...$headers] as $name => $value) {
            $request->setHeader($name, $value);
        }

        $response = (new InertiaResponse('Feed/Index', $props, 'v1', 'unused'))
            ->toResponse($request, service('response', null, false));

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>
     */
    private function mergeLists(array $page): array
    {
        return array_intersect_key($page, array_flip(['mergeProps', 'prependProps', 'deepMergeProps', 'matchPropsOn']));
    }

    public function testInvokeReturnsAnArrayAsIsAndCallsAClosureOnlyWhenInvoked(): void
    {
        $calls = new ArrayObject();
        $lazy  = new MergeProp(static function () use ($calls): array {
            $calls->append('resolved');

            return [3];
        });

        $this->assertSame([1, 2], (new MergeProp([1, 2]))());
        $this->assertCount(0, $calls);
        $this->assertSame([3], $lazy());
        $this->assertCount(1, $calls);
    }

    public function testFullRenderSendsEveryValueAndListsKeysByMode(): void
    {
        $page = $this->renderPage([
            'a'        => 1,
            'users'    => new MergeProp([1, 2]),
            'feed'     => (new MergeProp(static fn (): array => [9]))->prepend(),
            'settings' => (new MergeProp(['k' => 1]))->deepMerge(),
            'posts'    => (new MergeProp([4]))->matchOn('id'),
            'tags'     => (new MergeProp([5]))->prepend()->matchOn(['id', 'slug']),
        ]);

        $this->assertSame([1, 2], $page['props']['users']);
        $this->assertSame([9], $page['props']['feed']);
        $this->assertSame(['k' => 1], $page['props']['settings']);
        $this->assertSame(['users', 'posts'], $page['mergeProps']);
        $this->assertSame(['feed', 'tags'], $page['prependProps']);
        $this->assertSame(['settings'], $page['deepMergeProps']);
        $this->assertSame(['posts.id', 'tags.id', 'tags.slug'], $page['matchPropsOn']);
    }

    public function testPageWithoutMergePropsHasNoMergeListsEvenWithAResetHeader(): void
    {
        $page = $this->renderPage(['a' => 1], ['X-Inertia-Reset' => 'a']);

        $this->assertSame(['component', 'props', 'url', 'version'], array_keys($page));
    }

    public function testIntegerLikeKeyIsListedAsAString(): void
    {
        $page = $this->renderPage([1 => new MergeProp([1])]);

        $this->assertSame(['1'], $page['mergeProps']);
    }

    public function testModeSwitchLeavesTheOriginalInstanceInAppendMode(): void
    {
        $original = new MergeProp([1]);
        $original->prepend();

        $this->assertSame(['mergeProps' => ['p']], $this->mergeLists($this->renderPage(['p' => $original])));
    }

    public function testDeepMergeWinsOverPrependWhateverTheCallOrder(): void
    {
        $before = $this->renderPage(['p' => (new MergeProp([1]))->prepend()->deepMerge()]);
        $after  = $this->renderPage(['p' => (new MergeProp([1]))->deepMerge()->prepend()]);

        $this->assertSame(['deepMergeProps' => ['p']], $this->mergeLists($before));
        $this->assertSame(['deepMergeProps' => ['p']], $this->mergeLists($after));
    }

    public function testEveryFluentCallReturnsANewInstanceAndLeavesTheOriginalUnchanged(): void
    {
        $original = new MergeProp([1]);

        $calls = [
            $original->deepMerge(),
            $original->matchOn('id'),
            $original->append('data', 'id'),
            $original->prepend('data'),
            $original->prepend(),
            $original->merge(),
        ];

        foreach ($calls as $changed) {
            $this->assertNotSame($original, $changed);
        }

        $this->assertFalse($original->shouldDeepMerge());
        $this->assertSame([], $original->matchesOn());
        $this->assertSame([], $original->appendsAtPaths());
        $this->assertSame([], $original->prependsAtPaths());
        $this->assertTrue($original->appendsAtRoot());
    }

    public function testEveryFluentCallOnADeferPropReturnsANewInstanceAndLeavesTheOriginalUnmerged(): void
    {
        $original = new DeferProp(static fn (): int => 1, 'sidebar');

        foreach ([$original->merge(), $original->deepMerge(), $original->matchOn('id'), $original->append('d'), $original->prepend(), $original->defer('other')] as $changed) {
            $this->assertNotSame($original, $changed);
        }

        $this->assertFalse($original->shouldMerge());
        $this->assertSame('sidebar', $original->group());
        $this->assertSame('sidebar', $original->merge()->deepMerge()->matchOn('id')->group());
        $this->assertSame('other', $original->defer('other')->merge()->group());
    }

    public function testPathsAccumulateSeparatelyPerDirectionAndLeaveTheRoot(): void
    {
        $prop = (new MergeProp([1]))->append('a')->append('b')->prepend('c')->prepend('d');

        $this->assertSame(['a', 'b'], $prop->appendsAtPaths());
        $this->assertSame(['c', 'd'], $prop->prependsAtPaths());
        $this->assertFalse($prop->appendsAtRoot());
        $this->assertFalse($prop->prependsAtRoot());
    }

    public function testAReversedRootDirectionWithPathsIsNeitherRootDirection(): void
    {
        $prop = (new MergeProp([1]))->append(false)->append('x');

        $this->assertFalse($prop->prependsAtRoot());
        $this->assertFalse($prop->appendsAtRoot());
    }

    public function testPrependPathsDoNotTouchTheAppendPaths(): void
    {
        $prop = (new MergeProp([1]))->prepend('c');

        $this->assertSame([], $prop->appendsAtPaths());
        $this->assertSame(['c'], $prop->prependsAtPaths());
    }

    public function testPathMatchFieldsAccumulateAfterAnExistingMatchOn(): void
    {
        $prop = (new MergeProp([1]))->matchOn('x')->append('data', 'id')->prepend('meta', 'uuid');

        $this->assertSame(['x', 'data.id', 'meta.uuid'], $prop->matchesOn());
    }

    public function testABlankPathMatchFieldAddsNoMatch(): void
    {
        $this->assertSame([], (new MergeProp([1]))->append('data', '')->matchesOn());
        $this->assertSame([], (new MergeProp([1]))->prepend('data', '')->matchesOn());
    }

    public function testBooleanDirectionsSetTheRootDirection(): void
    {
        $this->assertTrue((new MergeProp([1]))->prepend()->prependsAtRoot());
        $this->assertTrue((new MergeProp([1]))->append(false)->prependsAtRoot());
        $this->assertTrue((new MergeProp([1]))->prepend(false)->appendsAtRoot());
        $this->assertTrue((new MergeProp([1]))->prepend()->append()->appendsAtRoot());
    }

    public function testADeferPropFollowsTheSameDirectionDefaults(): void
    {
        $prop = new DeferProp(static fn (): int => 1);

        $this->assertTrue($prop->merge()->prepend()->prependsAtRoot());
        $this->assertTrue($prop->merge()->prepend()->append()->appendsAtRoot());
        $this->assertFalse($prop->shouldMerge());
        $this->assertFalse($prop->shouldDeepMerge());
        $this->assertTrue($prop->deepMerge()->shouldMerge());
    }

    public function testThePublicConstructorsTakeOnlyTheParametersTheyDocument(): void
    {
        $merge = (new ReflectionClass(MergeProp::class))->getConstructor();
        $defer = (new ReflectionClass(DeferProp::class))->getConstructor();

        $this->assertSame(['value'], array_map(static fn (ReflectionParameter $p): string => $p->getName(), $merge?->getParameters() ?? []));
        $this->assertSame(['callback', 'group'], array_map(static fn (ReflectionParameter $p): string => $p->getName(), $defer?->getParameters() ?? []));
    }

    public function testAMergePropIsMergedOnConstructionAndADeferPropOnlyAfterMerge(): void
    {
        $this->assertTrue((new MergeProp([1]))->shouldMerge());
        $this->assertFalse((new DeferProp(static fn (): int => 1))->shouldMerge());
        $this->assertTrue((new DeferProp(static fn (): int => 1))->merge()->shouldMerge());
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideAPathMatchFieldIsRecordedOnlyWhenTruthy')]
    public function testAPathMatchFieldIsRecordedOnlyWhenTruthy(?string $field, array $expected): void
    {
        $this->assertSame($expected, (new MergeProp([1]))->append('data', $field)->matchesOn());
        $this->assertSame($expected, (new MergeProp([1]))->prepend('data', $field)->matchesOn());
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function provideAPathMatchFieldIsRecordedOnlyWhenTruthy(): iterable
    {
        yield 'a field' => ['id', ['data.id']];

        yield 'the string zero' => ['0', []];

        yield 'an empty string' => ['', []];

        yield 'no field' => [null, []];
    }

    public function testMatchOnWithoutMergeLeavesADeferPropOutOfEveryMergeList(): void
    {
        $page = $this->renderPage(['stats' => (new DeferProp(static fn (): int => 1))->matchOn('id')]);

        $this->assertSame([], $this->mergeLists($page));
    }

    public function testMatchFieldsSurviveALaterModeCall(): void
    {
        $page = $this->renderPage(['p' => (new MergeProp([1]))->matchOn('id')->deepMerge()]);

        $this->assertSame(['deepMergeProps' => ['p'], 'matchPropsOn' => ['p.id']], $this->mergeLists($page));
    }

    public function testMatchOnTakesAListOfFields(): void
    {
        $page = $this->renderPage(['p' => (new MergeProp([1]))->matchOn(['id', 'slug'])]);

        $this->assertSame(['mergeProps' => ['p'], 'matchPropsOn' => ['p.id', 'p.slug']], $this->mergeLists($page));
    }

    public function testIntegerLikeKeyIsLeftOutOfEveryListWhenResetButStillSent(): void
    {
        $page = $this->renderPage([1 => (new MergeProp([7]))->matchOn('id')], ['X-Inertia-Reset' => '1']);

        $this->assertSame([7], $page['props'][1]);
        $this->assertSame([], $this->mergeLists($page));
    }

    public function testIntegerLikeKeyNamedByPartialDataIsListed(): void
    {
        $partial = ['X-Inertia-Partial-Component' => 'Feed/Index'];
        $page    = $this->renderPage([1 => new MergeProp([7]), 2 => new MergeProp([8])], [...$partial, 'X-Inertia-Partial-Data' => '1']);

        $this->assertSame(['mergeProps' => ['1']], $this->mergeLists($page));
    }

    public function testIntegerLikeKeyNamedByPartialExceptIsLeftOutOfEveryList(): void
    {
        $partial = ['X-Inertia-Partial-Component' => 'Feed/Index'];
        $page    = $this->renderPage([1 => new MergeProp([7])], [...$partial, 'X-Inertia-Partial-Except' => '1']);

        $this->assertSame([], $this->mergeLists($page));
    }

    public function testResetKeyIsStillSentButLeftOutOfItsList(): void
    {
        $page = $this->renderPage(
            ['users' => new MergeProp([1]), 'feed' => (new MergeProp([2]))->prepend()],
            ['X-Inertia-Reset' => 'users'],
        );

        $this->assertSame([1], $page['props']['users']);
        $this->assertSame(['prependProps' => ['feed']], $this->mergeLists($page));
    }

    public function testResetHeaderNamesAreNotTrimmedSoAnItemWithALeadingSpaceResetsNothing(): void
    {
        $page = $this->renderPage(
            [
                'users' => new MergeProp([1]),
                'feed'  => (new MergeProp([2]))->prepend(),
                'posts' => (new MergeProp([3]))->matchOn('id'),
            ],
            ['X-Inertia-Reset' => 'users, posts'],
        );

        $this->assertSame(
            ['mergeProps' => ['posts'], 'prependProps' => ['feed'], 'matchPropsOn' => ['posts.id']],
            $this->mergeLists($page),
        );
    }

    public function testResetHeaderNamesEveryKeyAndItsMatchFieldsWhenCommaSeparated(): void
    {
        $page = $this->renderPage(
            [
                'users' => new MergeProp([1]),
                'feed'  => (new MergeProp([2]))->prepend(),
                'posts' => (new MergeProp([3]))->matchOn('id'),
            ],
            ['X-Inertia-Reset' => 'users,posts'],
        );

        $this->assertSame(['prependProps' => ['feed']], $this->mergeLists($page));
    }

    public function testResetHeaderNamingAnUnknownKeyLeavesTheListsUnchanged(): void
    {
        $props = ['users' => new MergeProp([1]), 'posts' => (new MergeProp([3]))->matchOn('id')];

        $this->assertSame(
            $this->renderPage($props),
            $this->renderPage($props, ['X-Inertia-Reset' => 'nope']),
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $expected
     */
    #[DataProvider('providePartialReloadNarrowsTheListsLikeTheProps')]
    public function testPartialReloadNarrowsTheListsLikeTheProps(array $headers, array $expected): void
    {
        $page = $this->renderPage([
            'users' => new MergeProp([1]),
            'feed'  => (new MergeProp([2]))->prepend(),
            'posts' => (new MergeProp([3]))->matchOn('id'),
        ], $headers);

        $this->assertSame($expected, $this->mergeLists($page));
    }

    public static function providePartialReloadNarrowsTheListsLikeTheProps(): iterable
    {
        $partial = ['X-Inertia-Partial-Component' => 'Feed/Index'];

        yield 'data names one key' => [
            [...$partial, 'X-Inertia-Partial-Data' => 'users'],
            ['mergeProps' => ['users']],
        ];

        yield 'except names one key with blank data' => [
            [...$partial, 'X-Inertia-Partial-Except' => 'users'],
            ['mergeProps' => ['posts'], 'prependProps' => ['feed'], 'matchPropsOn' => ['posts.id']],
        ];

        yield 'data and except name the same key' => [
            [...$partial, 'X-Inertia-Partial-Data' => 'users', 'X-Inertia-Partial-Except' => 'users'],
            [],
        ];

        yield 'another component keeps the full render lists' => [
            ['X-Inertia-Partial-Component' => 'Other', 'X-Inertia-Partial-Data' => 'users'],
            ['mergeProps' => ['users', 'posts'], 'prependProps' => ['feed'], 'matchPropsOn' => ['posts.id']],
        ];
    }

    public function testListingAKeyNeverInvokesAFilteredOutCallback(): void
    {
        $calls = new ArrayObject();
        $this->renderPage([
            'users' => new MergeProp(static function () use ($calls): array {
                $calls->append('resolved');

                return [];
            }),
            'a' => 1,
        ], ['X-Inertia-Partial-Component' => 'Feed/Index', 'X-Inertia-Partial-Data' => 'a']);

        $this->assertCount(0, $calls);
    }

    public function testMergeOnceIsListedUnderOnceAndStillMergedOnAFirstVisit(): void
    {
        $page = $this->renderPage(['feed' => (new MergeProp([1]))->once()]);

        $this->assertSame(['feed'], $page['mergeProps']);
        $this->assertSame(['feed' => ['prop' => 'feed', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testMergeOnceTheClientAlreadyHasIsNotSentAgain(): void
    {
        $page = $this->renderPage(['feed' => (new MergeProp([1]))->once()], ['X-Inertia-Except-Once-Props' => 'feed']);

        $this->assertArrayNotHasKey('feed', $page['props']);
        $this->assertArrayHasKey('feed', $page['onceProps']);
    }

    public function testOnceModifiersLeaveTheOriginalMergePropUntouched(): void
    {
        $original = new MergeProp([1]);
        $original->once()->as('k')->fresh()->until(30);

        $this->assertFalse($original->shouldResolveOnce());
        $this->assertArrayNotHasKey('onceProps', $this->renderPage(['p' => $original]));
    }
}
