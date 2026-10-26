<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App as AppConfig;
use Ejklock\Ci4Inertia\AlwaysProp;
use Ejklock\Ci4Inertia\DeferProp;
use Ejklock\Ci4Inertia\InertiaResponse;
use Ejklock\Ci4Inertia\LazyProp;
use Ejklock\Ci4Inertia\MergeProp;
use Ejklock\Ci4Inertia\OptionalProp;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies `InertiaResponse` against the protocol contract in docs/architecture/inertia.md:
 * the page object's four keys and their values, the `X-Inertia` header as the sole
 * content-negotiation signal between the HTML shell and the JSON body, URL
 * normalization, and partial-reload prop filtering including the guarantee that an
 * unrequested `LazyProp` callback is never invoked, and `AlwaysProp`'s bypass of that same
 * filtering so a shared prop like `errors` survives every partial reload.
 *
 * @internal
 */
final class InertiaResponseTest extends CIUnitTestCase
{
    private const SHELL_VIEW = 'Ejklock\Ci4InertiaTestSupport\Views\shell';

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
    private function decodeDataPageAttribute(string $html): array
    {
        preg_match('/data-page="([^"]*)"/', $html, $matches);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testNonInertiaRequestRendersHtmlShellWithDataPageKeys(): void
    {
        $inertia = new InertiaResponse('Users/Index', ['users' => ['a', 'b']], 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse($this->makeRequest('users'), $this->makeResponse());
        $html     = (string) $response->getBody();

        $page = $this->decodeDataPageAttribute($html);

        $this->assertSame(['component', 'props', 'url', 'version'], array_keys($page));
        $this->assertSame('Users/Index', $page['component']);
    }

    public function testNonInertiaRequestDoesNotSendXInertiaHeader(): void
    {
        $inertia = new InertiaResponse('Users/Index', [], 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse($this->makeRequest('users'), $this->makeResponse());

        $this->assertFalse($response->hasHeader('X-Inertia'));
    }

    public function testInertiaRequestReturnsJsonPageObjectWithNoHtmlWrapper(): void
    {
        $inertia = new InertiaResponse('Users/Index', ['users' => ['a', 'b']], 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse(
            $this->makeRequest('users', ['X-Inertia' => 'true']),
            $this->makeResponse(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('true', $response->getHeaderLine('X-Inertia'));

        $page = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'component' => 'Users/Index',
            'props'     => ['users' => ['a', 'b']],
            'url'       => '/users',
            'version'   => 'v1',
        ], $page);
    }

    public function testPageUrlPreservesLeadingSlashAndQueryString(): void
    {
        $inertia = new InertiaResponse('Users/Index', [], 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse(
            $this->makeRequest('users/index?status=open', ['X-Inertia' => 'true']),
            $this->makeResponse(),
        );

        $page = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/users/index?status=open', $page['url']);
    }

    public function testPartialReloadAllowListResolvesOnlyRequestedPropsAndNeverInvokesTheRest(): void
    {
        $countsInvoked = false;
        $props         = [
            'users'  => ['a', 'b'],
            'counts' => new LazyProp(static function () use (&$countsInvoked) {
                $countsInvoked = true;

                return ['open' => 3];
            }),
        ];

        $inertia = new InertiaResponse('Users/Index', $props, 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse(
            $this->makeRequest('users', [
                'X-Inertia'                   => 'true',
                'X-Inertia-Partial-Component' => 'Users/Index',
                'X-Inertia-Partial-Data'      => 'users',
            ]),
            $this->makeResponse(),
        );

        $page = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('users', $page['props']);
        $this->assertArrayNotHasKey('counts', $page['props']);
        $this->assertFalse($countsInvoked);
    }

    public function testPartialReloadExceptListRemovesNamedPropsAndNeverInvokesThem(): void
    {
        $countsInvoked = false;
        $props         = [
            'users'  => ['a', 'b'],
            'counts' => new LazyProp(static function () use (&$countsInvoked) {
                $countsInvoked = true;

                return ['open' => 3];
            }),
        ];

        $inertia = new InertiaResponse('Users/Index', $props, 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse(
            $this->makeRequest('users', [
                'X-Inertia'                   => 'true',
                'X-Inertia-Partial-Component' => 'Users/Index',
                'X-Inertia-Partial-Except'    => 'counts',
            ]),
            $this->makeResponse(),
        );

        $page = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('users', $page['props']);
        $this->assertArrayNotHasKey('counts', $page['props']);
        $this->assertFalse($countsInvoked);
    }

    public function testPartialHeadersAreIgnoredWhenComponentDoesNotMatch(): void
    {
        $countsInvoked = false;
        $props         = [
            'users'  => ['a', 'b'],
            'counts' => new LazyProp(static function () use (&$countsInvoked) {
                $countsInvoked = true;

                return ['open' => 3];
            }),
        ];

        $inertia = new InertiaResponse('Users/Index', $props, 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse(
            $this->makeRequest('users', [
                'X-Inertia'                   => 'true',
                'X-Inertia-Partial-Component' => 'Settings/Index',
                'X-Inertia-Partial-Data'      => 'users',
            ]),
            $this->makeResponse(),
        );

        $page = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('users', $page['props']);
        $this->assertArrayHasKey('counts', $page['props']);
        $this->assertTrue($countsInvoked);
    }

    public function testAlwaysPropIsPresentOnPartialReloadThatDidNotRequestIt(): void
    {
        $props = [
            'users'  => ['a', 'b'],
            'errors' => new AlwaysProp(static fn () => (object) []),
        ];

        $inertia = new InertiaResponse('Users/Index', $props, 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse(
            $this->makeRequest('users', [
                'X-Inertia'                   => 'true',
                'X-Inertia-Partial-Component' => 'Users/Index',
                'X-Inertia-Partial-Data'      => 'users',
            ]),
            $this->makeResponse(),
        );

        $page = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('users', $page['props']);
        $this->assertArrayHasKey('errors', $page['props']);
        $this->assertSame([], $page['props']['errors']);
    }

    public function testAlwaysPropRemainsPresentWhenListedInPartialExcept(): void
    {
        $errorsInvoked = false;
        $props         = [
            'users'  => ['a', 'b'],
            'errors' => new AlwaysProp(static function () use (&$errorsInvoked) {
                $errorsInvoked = true;

                return ['title' => 'is required'];
            }),
        ];

        $inertia = new InertiaResponse('Users/Index', $props, 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse(
            $this->makeRequest('users', [
                'X-Inertia'                   => 'true',
                'X-Inertia-Partial-Component' => 'Users/Index',
                'X-Inertia-Partial-Except'    => 'errors',
            ]),
            $this->makeResponse(),
        );

        $page = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('errors', $page['props']);
        $this->assertSame(['title' => 'is required'], $page['props']['errors']);
        $this->assertTrue($errorsInvoked);
    }

    public function testAlwaysPropResolvesNormallyOnFullRender(): void
    {
        $props = [
            'users'  => ['a', 'b'],
            'errors' => new AlwaysProp(static fn () => (object) []),
        ];

        $inertia = new InertiaResponse('Users/Index', $props, 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse(
            $this->makeRequest('users', ['X-Inertia' => 'true']),
            $this->makeResponse(),
        );

        $page = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('errors', $page['props']);
        $this->assertSame([], $page['props']['errors']);
    }

    /**
     * Renders a page holding one plain prop and one optional prop for the request headers, and
     * reports the props sent and how many times the optional prop's callback ran.
     *
     * @param array<string, string> $headers
     *
     * @return array{props: array<string, mixed>, invocations: int}
     */
    private function renderWithOptionalProp(array $headers): array
    {
        $invocations = 0;
        $props       = [
            'users' => ['a', 'b'],
            'posts' => new OptionalProp(static function () use (&$invocations) {
                $invocations++;

                return ['available' => true];
            }),
        ];

        $response = (new InertiaResponse('Settings/Index', $props, 'v1', self::SHELL_VIEW))->toResponse(
            $this->makeRequest('settings', ['X-Inertia' => 'true', ...$headers]),
            $this->makeResponse(),
        );
        $page = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return ['props' => $page['props'], 'invocations' => $invocations];
    }

    public function testOptionalPropIsLeftOutOfAFullRenderAndNeverInvoked(): void
    {
        $result = $this->renderWithOptionalProp([]);

        $this->assertSame(['users'], array_keys($result['props']));
        $this->assertSame(0, $result['invocations']);
    }

    public function testOptionalPropIsSentAndInvokedOnceWhenAPartialReloadNamesIt(): void
    {
        $result = $this->renderWithOptionalProp([
            'X-Inertia-Partial-Component' => 'Settings/Index',
            'X-Inertia-Partial-Data'      => 'posts',
        ]);

        $this->assertSame(['posts' => ['available' => true]], $result['props']);
        $this->assertSame(1, $result['invocations']);
    }

    public function testOptionalPropIsLeftOutOfAPartialReloadNamingOnlyOtherProps(): void
    {
        $result = $this->renderWithOptionalProp([
            'X-Inertia-Partial-Component' => 'Settings/Index',
            'X-Inertia-Partial-Data'      => 'users',
        ]);

        $this->assertSame(['users'], array_keys($result['props']));
        $this->assertSame(0, $result['invocations']);
    }

    public function testOptionalPropIsLeftOutWhenAPartialReloadNamesItAndExceptsIt(): void
    {
        $result = $this->renderWithOptionalProp([
            'X-Inertia-Partial-Component' => 'Settings/Index',
            'X-Inertia-Partial-Data'      => 'users,posts',
            'X-Inertia-Partial-Except'    => 'posts',
        ]);

        $this->assertSame(['users'], array_keys($result['props']));
        $this->assertSame(0, $result['invocations']);
    }

    public function testOptionalPropIsLeftOutOfAPartialReloadForAnotherComponent(): void
    {
        $result = $this->renderWithOptionalProp([
            'X-Inertia-Partial-Component' => 'Users/Index',
            'X-Inertia-Partial-Data'      => 'posts',
        ]);

        $this->assertSame(['users'], array_keys($result['props']));
        $this->assertSame(0, $result['invocations']);
    }

    public function testOptionalPropIsLeftOutOfAPartialReloadWithNoDataHeader(): void
    {
        $result = $this->renderWithOptionalProp(['X-Inertia-Partial-Component' => 'Settings/Index']);

        $this->assertSame(['users'], array_keys($result['props']));
        $this->assertSame(0, $result['invocations']);
    }

    /**
     * Renders a page of one plain prop, an always-sent prop and deferred props across two groups
     * for the request headers, and reports the page object plus which deferred callbacks ran.
     *
     * @param array<string, string> $headers
     *
     * @return array{page: array<string, mixed>, invoked: list<string>}
     */
    private function renderWithDeferredProps(array $headers, bool $inertia = true): array
    {
        $invoked = [];
        $defer   = static function (string $key, string $group = 'default') use (&$invoked): DeferProp {
            return new DeferProp(static function () use (&$invoked, $key): string {
                $invoked[] = $key;

                return 'value-' . $key;
            }, $group);
        };
        $props = [
            'a'      => 1,
            'b'      => $defer('b'),
            'c'      => $defer('c', 'sidebar'),
            'd'      => $defer('d', 'sidebar'),
            'errors' => new AlwaysProp(static fn (): array => []),
        ];

        $request  = $this->makeRequest('settings', $inertia ? ['X-Inertia' => 'true', ...$headers] : $headers);
        $response = (new InertiaResponse('Settings/Index', $props, 'v1', self::SHELL_VIEW))
            ->toResponse($request, $this->makeResponse());
        $page = $inertia
            ? json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)
            : $this->decodeDataPageAttribute((string) $response->getBody());

        return ['page' => $page, 'invoked' => $invoked];
    }

    public function testDeferredPropsAreLeftOutOfAFirstVisitAndListedByGroup(): void
    {
        $result = $this->renderWithDeferredProps([], false);

        $this->assertSame(['a', 'errors'], array_keys($result['page']['props']));
        $this->assertSame(['default' => ['b'], 'sidebar' => ['c', 'd']], $result['page']['deferredProps']);
        $this->assertSame([], $result['invoked']);
    }

    public function testDeferredPropsAreLeftOutOfAnInertiaRequestWithoutPartialHeaders(): void
    {
        $result = $this->renderWithDeferredProps([]);

        $this->assertSame(['a', 'errors'], array_keys($result['page']['props']));
        $this->assertSame(['default' => ['b'], 'sidebar' => ['c', 'd']], $result['page']['deferredProps']);
        $this->assertSame([], $result['invoked']);
    }

    public function testDeferredPropsAreListedWhenThePartialReloadIsForAnotherComponent(): void
    {
        $result = $this->renderWithDeferredProps([
            'X-Inertia-Partial-Component' => 'Users/Index',
            'X-Inertia-Partial-Data'      => 'b',
        ]);

        $this->assertSame(['a', 'errors'], array_keys($result['page']['props']));
        $this->assertSame(['default' => ['b'], 'sidebar' => ['c', 'd']], $result['page']['deferredProps']);
        $this->assertSame([], $result['invoked']);
    }

    public function testPageWithoutDeferredPropsKeepsExactlyTheFourPageKeys(): void
    {
        $inertia = new InertiaResponse('Users/Index', ['a' => 1], 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse($this->makeRequest('users', ['X-Inertia' => 'true']), $this->makeResponse());
        $page     = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['component', 'props', 'url', 'version'], array_keys($page));
    }

    public function testPartialReloadNamingDeferredPropsResolvesOnlyThoseAndOmitsTheDeferredList(): void
    {
        $result = $this->renderWithDeferredProps([
            'X-Inertia-Partial-Component' => 'Settings/Index',
            'X-Inertia-Partial-Data'      => 'c,d',
        ]);

        $this->assertSame(['c' => 'value-c', 'd' => 'value-d', 'errors' => []], $result['page']['props']);
        $this->assertSame(['c', 'd'], $result['invoked']);
        $this->assertArrayNotHasKey('deferredProps', $result['page']);
    }

    public function testPartialReloadNamingOneDeferredPropResolvesOnlyThatOne(): void
    {
        $result = $this->renderWithDeferredProps([
            'X-Inertia-Partial-Component' => 'Settings/Index',
            'X-Inertia-Partial-Data'      => 'b',
        ]);

        $this->assertSame(['b' => 'value-b', 'errors' => []], $result['page']['props']);
        $this->assertSame(['b'], $result['invoked']);
    }

    public function testPartialReloadWithBlankDataSendsNoDeferredPropAndInvokesNone(): void
    {
        $result = $this->renderWithDeferredProps([
            'X-Inertia-Partial-Component' => 'Settings/Index',
            'X-Inertia-Partial-Except'    => 'a',
        ]);

        $this->assertSame(['errors' => []], $result['page']['props']);
        $this->assertSame([], $result['invoked']);
        $this->assertArrayNotHasKey('deferredProps', $result['page']);
    }

    public function testPartialReloadThatNamesAndExceptsADeferredPropDoesNotSendIt(): void
    {
        $result = $this->renderWithDeferredProps([
            'X-Inertia-Partial-Component' => 'Settings/Index',
            'X-Inertia-Partial-Data'      => 'b',
            'X-Inertia-Partial-Except'    => 'b',
        ]);

        $this->assertSame(['errors' => []], $result['page']['props']);
        $this->assertSame([], $result['invoked']);
    }

    public function testDeferPropGroupDefaultsToDefault(): void
    {
        $this->assertSame('default', (new DeferProp(static fn (): int => 1))->group());
    }

    public function testDeferPropKeepsTheGroupItIsGiven(): void
    {
        $this->assertSame('sidebar', (new DeferProp(static fn (): int => 1, 'sidebar'))->group());
    }

    public function testDeferPropAcceptsAGroupNameContainingSpaces(): void
    {
        $this->assertSame('side bar', (new DeferProp(static fn (): int => 1, 'side bar'))->group());
    }

    #[DataProvider('provideDeferPropRejectsABlankGroup')]
    public function testDeferPropRejectsABlankGroup(string $group): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('group');

        new DeferProp(static fn (): int => 1, $group);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDeferPropRejectsABlankGroup(): iterable
    {
        yield 'empty' => [''];

        yield 'whitespace only' => ['   '];
    }

    public function testDeferPropResolveReturnsTheCallbackResult(): void
    {
        $this->assertSame(['x' => 1], (new DeferProp(static fn (): array => ['x' => 1]))->resolve());
    }

    public function testFirstVisitEmbedsTheMergeListsInTheHtmlShell(): void
    {
        $props = [
            'a'        => 1,
            'users'    => new MergeProp([1, 2]),
            'feed'     => (new MergeProp(static fn (): array => [9]))->prepend(),
            'settings' => (new MergeProp(['k' => 1]))->deepMerge(),
            'posts'    => (new MergeProp([4]))->matchOn('id'),
            'tags'     => (new MergeProp([5]))->prepend()->matchOn('id', 'slug'),
        ];

        $response = (new InertiaResponse('Users/Index', $props, 'v1', self::SHELL_VIEW))
            ->toResponse($this->makeRequest('users'), $this->makeResponse());
        $page = $this->decodeDataPageAttribute((string) $response->getBody());

        $this->assertSame([1, 2], $page['props']['users']);
        $this->assertSame([9], $page['props']['feed']);
        $this->assertSame(['users', 'posts'], $page['mergeProps']);
        $this->assertSame(['feed', 'tags'], $page['prependProps']);
        $this->assertSame(['settings'], $page['deepMergeProps']);
        $this->assertSame(['posts.id', 'tags.id', 'tags.slug'], $page['matchPropsOn']);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{page: array<string, mixed>, invoked: int}
     */
    private function renderWithMergeableDeferProp(DeferProp $stats, array $headers): array
    {
        $invoked = 0;
        $props   = ['a' => 1, 'stats' => $stats, 'plain' => new DeferProp(static function () use (&$invoked): int {
            $invoked++;

            return 1;
        })];

        $response = (new InertiaResponse('Settings/Index', $props, 'v1', self::SHELL_VIEW))
            ->toResponse($this->makeRequest('settings', ['X-Inertia' => 'true', ...$headers]), $this->makeResponse());

        return [
            'page'    => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR),
            'invoked' => $invoked,
        ];
    }

    public function testMergeableDeferPropIsListedForMergeWithoutBeingResolved(): void
    {
        $calls = 0;
        $stats = (new DeferProp(static function () use (&$calls): int {
            $calls++;

            return 5;
        }, 'g'))->merge();

        $result = $this->renderWithMergeableDeferProp($stats, []);

        $this->assertSame(['a'], array_keys($result['page']['props']));
        $this->assertSame(0, $calls);
        $this->assertSame(['g' => ['stats'], 'default' => ['plain']], $result['page']['deferredProps']);
        $this->assertSame(['stats'], $result['page']['mergeProps']);
    }

    public function testMergeableDeferPropFollowUpResolvesAndKeepsItsMergeList(): void
    {
        $stats  = (new DeferProp(static fn (): array => [5], 'g'))->merge();
        $result = $this->renderWithMergeableDeferProp($stats, [
            'X-Inertia-Partial-Component' => 'Settings/Index',
            'X-Inertia-Partial-Data'      => 'stats',
        ]);

        $this->assertSame(['stats' => [5]], $result['page']['props']);
        $this->assertSame(['stats'], $result['page']['mergeProps']);
        $this->assertArrayNotHasKey('deferredProps', $result['page']);
        $this->assertSame(0, $result['invoked']);
    }

    public function testDeferPropModesListUnderTheirOwnMergeKeys(): void
    {
        $deep    = $this->renderWithMergeableDeferProp((new DeferProp(static fn (): int => 1))->deepMerge(), []);
        $prepend = $this->renderWithMergeableDeferProp((new DeferProp(static fn (): int => 1))->prepend()->matchOn('id'), []);

        $this->assertSame(['stats'], $deep['page']['deepMergeProps']);
        $this->assertArrayNotHasKey('mergeProps', $deep['page']);
        $this->assertSame(['stats'], $prepend['page']['prependProps']);
        $this->assertSame(['stats.id'], $prepend['page']['matchPropsOn']);
    }

    public function testDeferPropWithoutAMergeCallIsInNoMergeList(): void
    {
        $result = $this->renderWithMergeableDeferProp(new DeferProp(static fn (): int => 1), []);

        $this->assertSame(['component', 'props', 'url', 'version', 'deferredProps'], array_keys($result['page']));
    }

    public function testMergeCallOnADeferPropKeepsItsGroupAndLeavesTheOriginalUnmergeable(): void
    {
        $original = new DeferProp(static fn (): int => 1, 'sidebar');
        $merged   = $original->merge();

        $this->assertSame('sidebar', $merged->group());
        $this->assertSame('sidebar', $original->prepend()->deepMerge()->matchOn('id')->group());
        $this->assertArrayNotHasKey('mergeProps', $this->renderWithMergeableDeferProp($original, [])['page']);
    }

    public function testAttributeEscapingPreventsBreakingOutOfDataPageAttribute(): void
    {
        $hostileValue = '"><script>alert(1)</script>&\'';

        $inertia = new InertiaResponse('Users/Index', ['note' => $hostileValue], 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse($this->makeRequest('users'), $this->makeResponse());
        $html     = (string) $response->getBody();

        $this->assertStringNotContainsString('<script>alert(1)', $html);

        $page = $this->decodeDataPageAttribute($html);

        $this->assertSame($hostileValue, $page['props']['note']);
    }

    /**
     * Property: for any requested subset of prop names, the resolved prop keys equal
     * exactly that subset and only those props' callbacks ran — proving laziness holds
     * for every combination the data provider enumerates, not merely the cases the other
     * tests happen to exercise.
     *
     * @param list<string> $allPropNames    Every prop name declared on the response.
     * @param list<string> $requestedSubset The subset named in `X-Inertia-Partial-Data`
     *                                      for this case.
     */
    #[DataProvider('providePartialReloadResolvedKeysAlwaysEqualTheRequestedSubset')]
    public function testPartialReloadResolvedKeysAlwaysEqualTheRequestedSubset(array $allPropNames, array $requestedSubset): void
    {
        $invoked = [];
        $props   = [];

        foreach ($allPropNames as $propName) {
            $props[$propName] = new LazyProp(static function () use (&$invoked, $propName) {
                $invoked[] = $propName;

                return $propName . '-value';
            });
        }

        $inertia = new InertiaResponse('Users/Index', $props, 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse(
            $this->makeRequest('users', [
                'X-Inertia'                   => 'true',
                'X-Inertia-Partial-Component' => 'Users/Index',
                'X-Inertia-Partial-Data'      => implode(',', $requestedSubset),
            ]),
            $this->makeResponse(),
        );

        $page = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $expected = $requestedSubset;
        sort($expected);

        /** @var list<string> $resolvedKeys */
        $resolvedKeys = array_keys($page['props']);
        sort($resolvedKeys);

        $invokedKeys = $invoked;
        sort($invokedKeys);

        $this->assertSame($expected, $resolvedKeys);
        $this->assertSame($expected, $invokedKeys);
    }

    /**
     * Enumerates every non-empty subset of three prop names as a partial-reload request,
     * so the exhaustive-subset property test proves the resolved-keys equality holds for
     * every possible combination of requested props, not just a hand-picked sample that
     * could hide an off-by-one in the filter.
     *
     * @return iterable<string, array{0: list<string>, 1: list<string>}>
     */
    public static function providePartialReloadResolvedKeysAlwaysEqualTheRequestedSubset(): iterable
    {
        $propNames = ['users', 'settings', 'counts'];

        /** @var list<list<string>> $subsets */
        $subsets = [[]];

        foreach ($propNames as $propName) {
            foreach ($subsets as $combination) {
                $subsets[] = [...$combination, $propName];
            }
        }

        foreach ($subsets as $subset) {
            if ($subset === []) {
                continue;
            }

            yield implode('+', $subset) => [$propNames, $subset];
        }
    }
}
