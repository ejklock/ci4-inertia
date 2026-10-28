<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use ArrayObject;
use CodeIgniter\Config\Factories;
use CodeIgniter\Format\Exceptions\FormatException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App as AppConfig;
use Config\Format;
use Ejklock\Ci4Inertia\AlwaysProp;
use Ejklock\Ci4Inertia\DeferProp;
use Ejklock\Ci4Inertia\InertiaResponse;
use Ejklock\Ci4Inertia\LazyProp;
use Ejklock\Ci4Inertia\MergeProp;
use Ejklock\Ci4Inertia\OptionalProp;
use Ejklock\Ci4Inertia\ResolvableProp;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

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

    /**
     * @param array<array-key, mixed> $props
     */
    #[DataProvider('provideDeferredPropsSerializeAsAnObjectKeyedByGroup')]
    public function testDeferredPropsSerializeAsAnObjectKeyedByGroup(array $props, string $expected): void
    {
        $inertia = new InertiaResponse('Settings/Index', $props, 'v1', self::SHELL_VIEW);

        $json = (string) preg_replace('/\s+/', '', (string) $inertia
            ->toResponse($this->makeRequest('settings', ['X-Inertia' => 'true']), $this->makeResponse())
            ->getBody());
        $html = html_entity_decode(
            (string) $inertia->toResponse($this->makeRequest('settings'), $this->makeResponse())->getBody(),
            ENT_QUOTES | ENT_HTML5,
        );

        $this->assertStringContainsString($expected, $json);
        $this->assertStringContainsString($expected, $html);
    }

    public static function provideDeferredPropsSerializeAsAnObjectKeyedByGroup(): iterable
    {
        $defer = static fn (string $group = 'default'): DeferProp => new DeferProp(static fn (): string => 'X', $group);

        yield 'integer-like group' => [['a' => $defer('0')], '"deferredProps":{"0":["a"]}'];

        yield 'contiguous integer-like groups' => [
            ['a' => $defer('0'), 'b' => $defer('1')],
            '"deferredProps":{"0":["a"],"1":["b"]}',
        ];

        yield 'named group' => [['a' => $defer()], '"deferredProps":{"default":["a"]}'];

        yield 'integer-like prop key' => [[1 => $defer()], '"deferredProps":{"default":["1"]}'];
    }

    public function testPageWithoutADeferPropHasNoDeferredPropsKeyInEitherForm(): void
    {
        $inertia = new InertiaResponse('Settings/Index', ['a' => 1], 'v1', self::SHELL_VIEW);

        $json = (string) $inertia
            ->toResponse($this->makeRequest('settings', ['X-Inertia' => 'true']), $this->makeResponse())
            ->getBody();
        $html = (string) $inertia->toResponse($this->makeRequest('settings'), $this->makeResponse())->getBody();

        $this->assertStringNotContainsString('deferredProps', $json);
        $this->assertStringNotContainsString('deferredProps', $html);
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

    public function testPageWithDefaultsCarriesNeitherHistoryKeyInEitherForm(): void
    {
        $inertia = new InertiaResponse('Users/Index', [], 'v1', self::SHELL_VIEW);

        $json = json_decode(
            (string) $inertia->toResponse($this->makeRequest('users', ['X-Inertia' => 'true']), $this->makeResponse())->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $html = $this->decodeDataPageAttribute(
            (string) $inertia->toResponse($this->makeRequest('users'), $this->makeResponse())->getBody(),
        );

        $this->assertSame(['component', 'props', 'url', 'version'], array_keys($json));
        $this->assertSame(['component', 'props', 'url', 'version'], array_keys($html));
    }

    public function testHistoryEncryptionIsListedOnlyWhenEnabled(): void
    {
        $inertia = new InertiaResponse('Users/Index', [], 'v1', self::SHELL_VIEW, true);

        $page = json_decode(
            (string) $inertia->toResponse($this->makeRequest('users', ['X-Inertia' => 'true']), $this->makeResponse())->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($page['encryptHistory']);
        $this->assertArrayNotHasKey('clearHistory', $page);
    }

    public function testHistoryClearingIsListedOnlyWhenRequested(): void
    {
        $inertia = new InertiaResponse('Users/Index', [], 'v1', self::SHELL_VIEW, false, true);

        $page = json_decode(
            (string) $inertia->toResponse($this->makeRequest('users', ['X-Inertia' => 'true']), $this->makeResponse())->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($page['clearHistory']);
        $this->assertArrayNotHasKey('encryptHistory', $page);
    }

    public function testHistoryFlagsSurviveAPartialReloadForTheSameComponent(): void
    {
        $inertia = new InertiaResponse('Users/Index', ['users' => ['a']], 'v1', self::SHELL_VIEW, true, true);

        $page = json_decode(
            (string) $inertia->toResponse($this->makeRequest('users', [
                'X-Inertia'                   => 'true',
                'X-Inertia-Partial-Component' => 'Users/Index',
                'X-Inertia-Partial-Data'      => 'users',
            ]), $this->makeResponse())->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($page['encryptHistory']);
        $this->assertTrue($page['clearHistory']);
    }

    public function testHistoryFlagsAreInTheHtmlShellDataPage(): void
    {
        $inertia = new InertiaResponse('Users/Index', [], 'v1', self::SHELL_VIEW, true, true);

        $page = $this->decodeDataPageAttribute(
            (string) $inertia->toResponse($this->makeRequest('users'), $this->makeResponse())->getBody(),
        );

        $this->assertTrue($page['encryptHistory']);
        $this->assertTrue($page['clearHistory']);
    }

    /**
     * @param array<string, mixed>  $props
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function renderProps(array $props, array $headers = []): array
    {
        $response = (new InertiaResponse('Users/Index', $props, 'v1', self::SHELL_VIEW))
            ->toResponse($this->makeRequest('users', ['X-Inertia' => 'true', ...$headers]), $this->makeResponse());

        return json_decode((string) $response->getBody(), true, 1024, JSON_THROW_ON_ERROR)['props'];
    }

    /**
     * @param array<string, mixed>  $props
     * @param array<string, string> $headers
     */
    private function assertRefusedOnBuild(array $props, string $message, array $headers = []): void
    {
        $thrown = null;

        try {
            $this->renderProps($props, $headers);
        } catch (InvalidArgumentException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $thrown);
        $this->assertSame($message, $thrown->getMessage());
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('provideResolvedEntityInsideAnArrayIsRefusedAtThePropKeyAndSubKey')]
    public function testResolvedEntityInsideAnArrayIsRefusedAtThePropKeyAndSubKey(callable $make, array $headers): void
    {
        $entity = new class () {
            public string $secret = 'x';
        };

        $this->assertRefusedOnBuild(
            ['key' => $make(static fn (): array => ['sub' => $entity])],
            'Inertia prop "key.sub" must resolve to only scalars, arrays, null and stdClass objects, class@anonymous given.',
            $headers,
        );
    }

    /**
     * @return iterable<string, array{0: callable(callable): ResolvableProp, 1: array<string, string>}>
     */
    public static function provideResolvedEntityInsideAnArrayIsRefusedAtThePropKeyAndSubKey(): iterable
    {
        yield 'a LazyProp' => [static fn (callable $c): ResolvableProp => new LazyProp($c), []];

        yield 'an AlwaysProp' => [static fn (callable $c): ResolvableProp => new AlwaysProp($c), []];

        yield 'a MergeProp' => [static fn (callable $c): ResolvableProp => new MergeProp($c(...)), []];

        yield 'a DeferProp loaded by a partial reload' => [
            static fn (callable $c): ResolvableProp => new DeferProp($c),
            ['X-Inertia-Partial-Component' => 'Users/Index', 'X-Inertia-Partial-Data' => 'key'],
        ];
    }

    public function testResolvedEntityAtTheTopIsRefusedAtThePropKey(): void
    {
        $this->assertRefusedOnBuild(
            ['key' => new LazyProp(static fn (): ArrayObject => new ArrayObject())],
            'Inertia prop "key" must resolve to only scalars, arrays, null and stdClass objects, ArrayObject given.',
        );
    }

    public function testEntityInsideAReturnedStdClassIsRefused(): void
    {
        $this->assertRefusedOnBuild(
            ['key' => new LazyProp(static fn (): object => (object) ['sub' => new ArrayObject()])],
            'Inertia prop "key.sub" must resolve to only scalars, arrays, null and stdClass objects, ArrayObject given.',
        );
    }

    public function testStdClassHoldingItselfIsRefusedAtItsPath(): void
    {
        $this->assertRefusedOnBuild(
            ['key' => new LazyProp(static function (): object {
                $bag     = new stdClass();
                $bag->me = $bag;

                return $bag;
            })],
            'Inertia prop "key.me" must resolve to only scalars, arrays, null and stdClass objects, stdClass given.',
        );
    }

    public function testResolvedArrayThatHoldsItselfThroughAReferenceIsRefused(): void
    {
        $this->assertRefusedOnBuild(
            ['a' => new LazyProp(static function (): array {
                $a         = ['x' => 1];
                $a['self'] = &$a;

                return $a;
            })],
            'Inertia prop "a" nests deeper than 512 levels.',
        );
    }

    public function testResolvedArrayNestedBeyondTheLevelLimitIsRefusedNamingOnlyTheTopLevelKey(): void
    {
        $this->assertRefusedOnBuild(
            ['deep' => new LazyProp(static fn (): array => self::nestedArray(513))],
            'Inertia prop "deep" nests deeper than 512 levels.',
        );
    }

    public function testResolvedStdClassNestedBeyondTheLevelLimitIsRefused(): void
    {
        $this->assertRefusedOnBuild(
            ['deep' => new LazyProp(static fn (): object => self::nestedBag(513))],
            'Inertia prop "deep" nests deeper than 512 levels.',
        );
    }

    /**
     * The page object wraps the props in two more levels, so a value this deep passes the
     * prop check and then fails at JSON encoding, which is where the exception type tells
     * the two apart.
     */
    #[DataProvider('provideResolvedValueNestedUpToTheLevelLimitPassesThePropCheck')]
    public function testResolvedValueNestedUpToTheLevelLimitPassesThePropCheck(int $levels, bool $bag): void
    {
        $inertia = new InertiaResponse('Users/Index', [
            'deep' => new LazyProp(static fn (): array|stdClass => $bag ? self::nestedBag($levels) : self::nestedArray($levels)),
        ], 'v1', self::SHELL_VIEW);

        $this->expectException(FormatException::class);

        $inertia->toResponse($this->makeRequest('users', ['X-Inertia' => 'true']), $this->makeResponse());
    }

    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function provideResolvedValueNestedUpToTheLevelLimitPassesThePropCheck(): iterable
    {
        yield 'an array at 511 levels' => [511, false];

        yield 'an array at 512 levels' => [512, false];

        yield 'a stdClass at 512 levels' => [512, true];
    }

    public function testResolvedValueHonorsARaisedJsonEncodeDepth(): void
    {
        $this->withJsonEncodeDepth(600, function (): void {
            $this->assertRefusedOnBuild(
                ['deep' => new LazyProp(static fn (): array => self::nestedArray(601))],
                'Inertia prop "deep" nests deeper than 600 levels.',
            );
        });
    }

    /**
     * The page object wraps the props in two more levels, so the raised limit leaves room for them.
     */
    public function testResolvedValueAtARaisedJsonEncodeDepthIsCarriedInTheXInertiaBody(): void
    {
        $this->withJsonEncodeDepth(700, function (): void {
            $props = $this->renderProps(['deep' => new LazyProp(static fn (): array => self::nestedArray(600))]);

            $this->assertSame(self::nestedArray(600), $props['deep']);
        });
    }

    public function testPropGivenDirectlyAtARaisedJsonEncodeDepthIsCarriedInTheXInertiaBody(): void
    {
        $this->withJsonEncodeDepth(700, function (): void {
            $props = $this->renderProps(['deep' => self::nestedArray(600)]);

            $this->assertSame(self::nestedArray(600), $props['deep']);
        });
    }

    public function testJsonEncodeDepthOverrideIsGoneAfterTheHelperRuns(): void
    {
        $this->withJsonEncodeDepth(600, function (): void {
            $this->assertSame(600, config(Format::class)->jsonEncodeDepth);
        });

        $this->assertSame(512, config(Format::class)->jsonEncodeDepth);
        $this->assertArrayNotHasKey('format.jsonEncodeDepth', $_SERVER);
    }

    public function testResolvedValueHonorsALoweredJsonEncodeDepth(): void
    {
        $this->withJsonEncodeDepth(8, function (): void {
            $this->assertRefusedOnBuild(
                ['deep' => new LazyProp(static fn (): array => self::nestedArray(9))],
                'Inertia prop "deep" nests deeper than 8 levels.',
            );
            $this->assertRefusedOnBuild(
                ['deep' => new LazyProp(static fn (): object => self::nestedBag(9))],
                'Inertia prop "deep" nests deeper than 8 levels.',
            );
            $this->assertRefusedOnBuild(
                ['a' => new LazyProp(static function (): array {
                    $a         = ['x' => 1];
                    $a['self'] = &$a;

                    return $a;
                })],
                'Inertia prop "a" nests deeper than 8 levels.',
            );

            $this->assertSame(
                self::nestedArray(6),
                $this->renderProps(['deep' => new LazyProp(static fn (): array => self::nestedArray(6))])['deep'],
            );
        });
    }

    private function withJsonEncodeDepth(int $depth, callable $test): void
    {
        $key      = 'format.jsonEncodeDepth';
        $existed  = array_key_exists($key, $_SERVER);
        $original = $_SERVER[$key] ?? null; // @phpstan-ignore codeigniter.superglobalAccess (config reads live $_SERVER, not the snapshot service)

        $_SERVER[$key] = (string) $depth; // @phpstan-ignore codeigniter.superglobalAccessAssign (config reads live $_SERVER, not the snapshot service)
        Factories::reset('config');

        try {
            $test();
        } finally {
            if ($existed) {
                $_SERVER[$key] = $original; // @phpstan-ignore codeigniter.superglobalAccessAssign (restores the live $_SERVER)
            } else {
                unset($_SERVER[$key]); // @phpstan-ignore codeigniter.superglobalAccess (restores the live $_SERVER)
            }
            Factories::reset('config');
        }
    }

    /**
     * @return array<int, mixed> The given number of arrays, each holding the next, the innermost empty.
     */
    private static function nestedArray(int $levels): array
    {
        $value = [];

        for ($i = 1; $i < $levels; $i++) {
            $value = [$value];
        }

        return $value;
    }

    /**
     * @return stdClass The given number of objects, each holding the next, the innermost empty.
     */
    private static function nestedBag(int $levels): stdClass
    {
        $value = new stdClass();

        for ($i = 1; $i < $levels; $i++) {
            $value = (object) ['n' => $value];
        }

        return $value;
    }

    public function testResolvedStdClassOfPlainDataAndPlainValuesAreSent(): void
    {
        $props = $this->renderProps([
            'bag'    => new LazyProp(static fn (): object => (object) ['a' => [1, null, 'x'], 'b' => (object) ['c' => 1.5]]),
            'list'   => new AlwaysProp(static fn (): array => [['x' => true]]),
            'scalar' => new LazyProp(static fn (): int => 3),
            'none'   => new LazyProp(static fn (): ?string => null),
        ]);

        $this->assertSame([
            'bag'    => ['a' => [1, null, 'x'], 'b' => ['c' => 1.5]],
            'list'   => [['x' => true]],
            'scalar' => 3,
            'none'   => null,
        ], $props);
    }

    public function testErrorsPropResolvingToAnEmptyStdClassIsSent(): void
    {
        $props = $this->renderProps(['errors' => new AlwaysProp(static fn (): object => (object) [])]);

        $this->assertSame([], $props['errors']);
    }

    public function testAnEntityBehindAPropLeftOutByAPartialReloadIsNeverResolved(): void
    {
        $invoked = false;
        $props   = $this->renderProps([
            'users'  => ['a'],
            'entity' => new LazyProp(static function () use (&$invoked): object {
                $invoked = true;

                return new ArrayObject();
            }),
        ], ['X-Inertia-Partial-Component' => 'Users/Index', 'X-Inertia-Partial-Data' => 'users']);

        $this->assertSame(['users' => ['a']], $props);
        $this->assertFalse($invoked);
    }
}
