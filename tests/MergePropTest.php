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
use Ejklock\Ci4Inertia\MergeMode;
use Ejklock\Ci4Inertia\MergeProp;
use Ejklock\Ci4Inertia\MergeState;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function testResolveReturnsAnArrayAsIsAndInvokesAClosureOnlyOnResolve(): void
    {
        $calls = new ArrayObject();
        $lazy  = new MergeProp(static function () use ($calls): array {
            $calls->append('resolved');

            return [3];
        });

        $this->assertSame([1, 2], (new MergeProp([1, 2]))->resolve());
        $this->assertCount(0, $calls);
        $this->assertSame([3], $lazy->resolve());
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
            'tags'     => (new MergeProp([5]))->prepend()->matchOn('id', 'slug'),
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

    public function testTheLastModeCallWins(): void
    {
        $deep    = $this->renderPage(['p' => (new MergeProp([1]))->prepend()->deepMerge()]);
        $prepend = $this->renderPage(['p' => (new MergeProp([1]))->deepMerge()->prepend()]);

        $this->assertSame(['deepMergeProps' => ['p']], $this->mergeLists($deep));
        $this->assertSame(['prependProps' => ['p']], $this->mergeLists($prepend));
    }

    public function testMatchFieldsSurviveALaterModeCall(): void
    {
        $page = $this->renderPage(['p' => (new MergeProp([1]))->matchOn('id')->deepMerge()]);

        $this->assertSame(['deepMergeProps' => ['p'], 'matchPropsOn' => ['p.id']], $this->mergeLists($page));
    }

    #[DataProvider('provideMatchOnRejectsABlankField')]
    public function testMatchOnRejectsABlankField(string $field, string ...$more): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MergeProp([1]))->matchOn($field, ...$more);
    }

    public static function provideMatchOnRejectsABlankField(): iterable
    {
        yield 'empty' => [''];

        yield 'whitespace after a valid field' => ['id', '  '];
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    #[DataProvider('provideMergeStateRejectsInvalidMatchFields')]
    public function testMergeStateRejectsInvalidMatchFields(array $fields): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MergeState(MergeMode::Append, $fields);
    }

    public static function provideMergeStateRejectsInvalidMatchFields(): iterable
    {
        yield 'empty field' => [['']];

        yield 'whitespace field' => [['id', '  ']];

        yield 'string keys' => [['a' => 'id']];

        yield 'gapped list' => [[1 => 'id']];

        yield 'non-string field' => [[7]];
    }

    public function testMatchOnRejectsABlankFieldOnADeferPropToo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DeferProp(static fn (): int => 1))->matchOn('');
    }

    public function testMergeStateKeepsAValidListOfFields(): void
    {
        $state = new MergeState(MergeMode::Deep, ['id', 'slug']);

        $this->assertSame(MergeMode::Deep, $state->mode);
        $this->assertSame(['id', 'slug'], $state->matchFields);
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

    public function testResetHeaderWithSpacesRemovesEveryNamedKeyAndItsMatchFields(): void
    {
        $page = $this->renderPage(
            [
                'users' => new MergeProp([1]),
                'feed'  => (new MergeProp([2]))->prepend(),
                'posts' => (new MergeProp([3]))->matchOn('id'),
            ],
            ['X-Inertia-Reset' => 'users, posts'],
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
}
