<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use CodeIgniter\Entity\Entity;
use CodeIgniter\Format\Exceptions\FormatException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\View\View;
use Config\App as AppConfig;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;
use Ejklock\Ci4Inertia\AlwaysProp;
use Ejklock\Ci4Inertia\DeferProp;
use Ejklock\Ci4Inertia\InertiaResponse;
use Ejklock\Ci4Inertia\MergeProp;
use Ejklock\Ci4Inertia\OptionalProp;
use Ejklock\Ci4Inertia\ProvidesInertiaProperties;
use Ejklock\Ci4Inertia\RenderContext;
use Ejklock\Ci4Inertia\ResponseFactory;
use InvalidArgumentException;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * Verifies `InertiaResponse` against the protocol contract in docs/architecture/inertia.md:
 * the page object's four keys and their values, the `X-Inertia` header as the sole
 * content-negotiation signal between the HTML shell and the JSON body, URL
 * normalization, and partial-reload prop filtering including the guarantee that an
 * unrequested closure prop is never invoked, and `AlwaysProp`'s bypass of that same
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
    private function decodePageScript(string $html): array
    {
        $this->assertSame(1, preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $html, $matches));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageOf(InertiaResponse $inertia): array
    {
        $response = $inertia->toResponse($this->makeRequest('users', ['X-Inertia' => 'true']), $this->makeResponse());

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testNonInertiaRequestRendersHtmlShellWithDataPageKeys(): void
    {
        $inertia = new InertiaResponse('Users/Index', ['users' => ['a', 'b']], 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse($this->makeRequest('users'), $this->makeResponse());
        $html     = (string) $response->getBody();

        $page = $this->decodePageScript($html);

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

    /**
     * Renders a page for a request whose server data carries the given raw request URI, restoring the server data afterwards.
     */
    private function renderWithRequestUri(string $requestUri, bool $inertia = true, ?InertiaResponse $page = null): string
    {
        $headers      = $inertia ? ['X-Inertia' => 'true'] : [];
        $request      = $this->makeRequest('users', $headers);
        $superglobals = service('superglobals');
        $server       = $superglobals->getServerArray();
        $request->setGlobal('server', ['REQUEST_URI' => $requestUri]);

        try {
            $page ??= new InertiaResponse('Users/Index', [], 'v1', self::SHELL_VIEW);

            return (string) $page->toResponse($request, $this->makeResponse())->getBody();
        } finally {
            $superglobals->setServerArray($server);
        }
    }

    private function pageUrlForRequestUri(string $requestUri): string
    {
        return json_decode($this->renderWithRequestUri($requestUri), true, flags: JSON_THROW_ON_ERROR)['url'];
    }

    #[DataProvider('providePageUrlIsTheRawRequestUriAsSeenFromTheHost')]
    public function testPageUrlIsTheRawRequestUriAsSeenFromTheHost(string $requestUri, string $expected): void
    {
        $this->assertSame($expected, $this->pageUrlForRequestUri($requestUri));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providePageUrlIsTheRawRequestUriAsSeenFromTheHost(): iterable
    {
        yield 'subfolder path' => ['/app/users', '/app/users'];

        yield 'subfolder path with query' => ['/app/users?page=2', '/app/users?page=2'];

        yield 'subfolder root' => ['/app/', '/app/'];

        yield 'root' => ['/', '/'];

        yield 'rewritten request stays without the index page' => ['/users', '/users'];

        yield 'index page used by the client is kept' => ['/index.php/users', '/index.php/users'];

        yield 'percent-encoded segment stays encoded' => ['/caf%C3%A9', '/caf%C3%A9'];

        yield 'absolute form drops scheme and authority' => ['http://example.com/app/users?x=1', '/app/users?x=1'];

        yield 'absolute form without a path' => ['http://example.com?x=1', '/?x=1'];

        yield 'absolute form scheme is matched in any case' => ['HTTP://Example.com/app/users?x=1', '/app/users?x=1'];

        yield 'missing leading slash is added' => ['users?a=1', '/users?a=1'];

        yield 'leading double slash collapses to one' => ['//evil.com/x', '/evil.com/x'];

        yield 'leading triple slash collapses to one' => ['///users', '/users'];

        yield 'leading slash and backslash collapse to one slash' => ['/\evil.com/x', '/evil.com/x'];

        yield 'leading double backslash becomes one slash' => ['\\\\evil.com', '/evil.com'];

        yield 'leading mixed run collapses to one slash' => ['/\/\x', '/x'];

        yield 'absolute form with a doubled leading slash collapses' => ['http://host//evil.com/x', '/evil.com/x'];

        yield 'interior double slash is kept' => ['/users//5', '/users//5'];

        yield 'interior backslash is kept' => ['/a\b', '/a\b'];

        yield 'double slash inside the query is kept' => ['/users?next=//evil.com', '/users?next=//evil.com'];

        yield 'trailing slash kept' => ['/users/', '/users/'];

        yield 'trailing slash kept before the query' => ['/users/?a=1', '/users/?a=1'];

        yield 'trailing slash kept under a subfolder' => ['/app/users/', '/app/users/'];

        yield 'no trailing slash added' => ['/users', '/users'];

        yield 'slash only inside the query' => ['/users?next=/home/', '/users?next=/home/'];

        yield 'query kept in the order and encoding sent' => ['/users?b=2&a=%2F1', '/users?b=2&a=%2F1'];
    }

    public function testPageUrlFromTheRequestUriAppearsInTheHtmlShell(): void
    {
        $html = $this->renderWithRequestUri('/app/users/?a=1', false);

        $this->assertSame('/app/users/?a=1', $this->decodePageScript($html)['url']);
    }

    public function testPageUrlFallsBackToTheRoutePathWhenTheRequestUriIsEmpty(): void
    {
        $this->assertSame('/users', $this->pageUrlForRequestUri(''));
    }

    public function testResolverDecidesThePageUrlEvenWhenARequestUriIsPresent(): void
    {
        $inertia = new InertiaResponse('C', [], 'v1', self::SHELL_VIEW, urlResolver: static fn (IncomingRequest $request): string => '/custom');

        $page = json_decode($this->renderWithRequestUri('/app/users/', true, $inertia), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/custom', $page['url']);
    }

    public function testRequestUriIsRestoredAfterRendering(): void
    {
        $before = service('superglobals')->getServerArray();

        $this->renderWithRequestUri('/app/users');

        $this->assertSame($before, service('superglobals')->getServerArray());
    }

    public function testPartialReloadAllowListResolvesOnlyRequestedPropsAndNeverInvokesTheRest(): void
    {
        $countsInvoked = false;
        $props         = [
            'users'  => ['a', 'b'],
            'counts' => static function () use (&$countsInvoked) {
                $countsInvoked = true;

                return ['open' => 3];
            },
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
            'counts' => static function () use (&$countsInvoked) {
                $countsInvoked = true;

                return ['open' => 3];
            },
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
            'counts' => static function () use (&$countsInvoked) {
                $countsInvoked = true;

                return ['open' => 3];
            },
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

    public function testOptionalPropIsSentByAPartialReloadWithNoDataHeader(): void
    {
        $result = $this->renderWithOptionalProp(['X-Inertia-Partial-Component' => 'Settings/Index']);

        $this->assertSame(['users', 'posts'], array_keys($result['props']));
        $this->assertSame(1, $result['invocations']);
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
            : $this->decodePageScript((string) $response->getBody());

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

    public function testPartialReloadWithNoDataSendsEveryDeferredPropNotExceptedAndOmitsTheDeferredList(): void
    {
        $result = $this->renderWithDeferredProps([
            'X-Inertia-Partial-Component' => 'Settings/Index',
            'X-Inertia-Partial-Except'    => 'a',
        ]);

        $this->assertSame(['b' => 'value-b', 'c' => 'value-c', 'd' => 'value-d', 'errors' => []], $result['page']['props']);
        $this->assertSame(['b', 'c', 'd'], $result['invoked']);
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

    public function testDeferPropInvokeReturnsTheCallbackResult(): void
    {
        $this->assertSame(['x' => 1], (new DeferProp(static fn (): array => ['x' => 1]))());
    }

    public function testFirstVisitEmbedsTheMergeListsInTheHtmlShell(): void
    {
        $props = [
            'a'        => 1,
            'users'    => new MergeProp([1, 2]),
            'feed'     => (new MergeProp(static fn (): array => [9]))->prepend(),
            'settings' => (new MergeProp(['k' => 1]))->deepMerge(),
            'posts'    => (new MergeProp([4]))->matchOn('id'),
            'tags'     => (new MergeProp([5]))->prepend()->matchOn(['id', 'slug']),
        ];

        $response = (new InertiaResponse('Users/Index', $props, 'v1', self::SHELL_VIEW))
            ->toResponse($this->makeRequest('users'), $this->makeResponse());
        $page = $this->decodePageScript((string) $response->getBody());

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
        $prepend = $this->renderWithMergeableDeferProp((new DeferProp(static fn (): int => 1))->merge()->prepend()->matchOn('id'), []);

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

    public function testAppendOnADeferPropWithoutMergeLeavesItInNoMergeList(): void
    {
        $result = $this->renderWithMergeableDeferProp((new DeferProp(static fn (): int => 1))->append('data'), []);

        $this->assertArrayHasKey('stats', array_flip($result['page']['deferredProps']['default']));
        $this->assertSame([], array_intersect_key($result['page'], array_flip(['mergeProps', 'prependProps', 'deepMergeProps', 'matchPropsOn'])));
    }

    public function testAppendAfterMergeOnADeferPropListsThePathInTheMergeMetadata(): void
    {
        $result = $this->renderWithMergeableDeferProp((new DeferProp(static fn (): int => 1))->merge()->append('data'), []);

        $this->assertSame(['stats.data'], $result['page']['mergeProps']);
    }

    public function testMergeCallOnADeferPropKeepsItsGroupAndLeavesTheOriginalUnmergeable(): void
    {
        $original = new DeferProp(static fn (): int => 1, 'sidebar');
        $merged   = $original->merge();

        $this->assertSame('sidebar', $merged->group());
        $this->assertSame('sidebar', $original->prepend()->deepMerge()->matchOn('id')->group());
        $this->assertArrayNotHasKey('mergeProps', $this->renderWithMergeableDeferProp($original, [])['page']);
    }

    public function testHostileMarkupInAPropCannotBreakOutOfTheScriptTag(): void
    {
        $hostileValue = '"><script>alert(1)</script>&\'';

        $inertia = new InertiaResponse('Users/Index', ['note' => $hostileValue], 'v1', self::SHELL_VIEW);

        $response = $inertia->toResponse($this->makeRequest('users'), $this->makeResponse());
        $html     = (string) $response->getBody();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertSame(1, substr_count($html, '</script>'));

        $page = $this->decodePageScript($html);

        $this->assertSame($hostileValue, $page['props']['note']);
    }

    public function testTheFirstVisitPageEqualsThePageSentToAnInertiaRequestForEveryOptionalKey(): void
    {
        $build = static fn (): InertiaResponse => new InertiaResponse(
            'Users/Index',
            [
                'users' => ['a', 'b'],
                'stats' => new DeferProp(static fn (): int => 1, 'sidebar'),
                'feed'  => (new MergeProp(static fn (): array => [1]))->deepMerge()->matchOn('id'),
            ],
            'v1',
            self::SHELL_VIEW,
            encryptHistory: true,
            clearHistory: true,
        );

        $html = (string) $build()->toResponse($this->makeRequest('users?q=1'), $this->makeResponse())->getBody();
        $json = (string) $build()->toResponse($this->makeRequest('users?q=1', ['X-Inertia' => 'true']), $this->makeResponse())->getBody();

        $sent = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($sent, $this->decodePageScript($html));
        $this->assertArrayHasKey('deferredProps', $sent);
        $this->assertArrayHasKey('deepMergeProps', $sent);
        $this->assertArrayHasKey('matchPropsOn', $sent);
        $this->assertTrue($sent['encryptHistory']);
        $this->assertTrue($sent['clearHistory']);
    }

    public function testThePreserveFragmentFlagAndTheFlashDataShowInBothWireForms(): void
    {
        $this->mockSession();
        $factory = new ResponseFactory($this->makeRequest(), self::SHELL_VIEW, static fn (): string => 'v1');
        $factory->preserveFragment();
        $factory->flash('toast', 'Saved');

        $build = static fn (): InertiaResponse => $factory->render('Users/Index', ['a' => 1])->flash('extra', true);

        $html = (string) $build()->toResponse($this->makeRequest('users'), $this->makeResponse())->getBody();

        $this->assertSame(['toast' => 'Saved', 'extra' => true], $this->decodePageScript($html)['flash']);
        $this->assertTrue($this->decodePageScript($html)['preserveFragment']);
    }

    public function testAPageBuiltWithoutTheFlagsCarriesNeitherKey(): void
    {
        $page = $this->decodePageScript((string) (new InertiaResponse('Users/Index', [], 'v1', self::SHELL_VIEW))
            ->toResponse($this->makeRequest('users'), $this->makeResponse())->getBody());

        $this->assertArrayNotHasKey('flash', $page);
        $this->assertArrayNotHasKey('preserveFragment', $page);
    }

    public function testTheFirstVisitMountElementCarriesNoDataPageAttribute(): void
    {
        $inertia = new InertiaResponse('Users/Index', [], 'v1', self::SHELL_VIEW);

        $html = (string) $inertia->toResponse($this->makeRequest('users'), $this->makeResponse())->getBody();

        $this->assertStringContainsString('</script><div id="app"></div>', $html);
        $this->assertSame(1, substr_count($html, 'data-page='));
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
            $props[$propName] = static function () use (&$invoked, $propName) {
                $invoked[] = $propName;

                return $propName . '-value';
            };
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
        $html = $this->decodePageScript(
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

    public function testWithoutSharedPropsThePageHasNoSharedPropsKey(): void
    {
        $page = $this->pageOf(new InertiaResponse('Users/Index', ['a' => 1], 'v1', self::SHELL_VIEW));

        $this->assertArrayNotHasKey('sharedProps', $page);
        $this->assertSame(['a' => 1], $page['props']);
    }

    public function testSharedPropsAreMergedUnderThePagePropsAndListedByTopLevelKey(): void
    {
        $inertia = new InertiaResponse(
            'Users/Index',
            ['b' => 2, 'a' => 'page'],
            'v1',
            self::SHELL_VIEW,
            false,
            false,
            ['a' => 'shared', 'auth' => ['id' => 1]],
        );

        $page = $this->pageOf($inertia);

        $this->assertSame(['a' => 'page', 'auth' => ['id' => 1], 'b' => 2], $page['props']);
        $this->assertSame(['a', 'auth'], $page['sharedProps']);
    }

    public function testHistoryFlagsAreInTheHtmlShellDataPage(): void
    {
        $inertia = new InertiaResponse('Users/Index', [], 'v1', self::SHELL_VIEW, true, true);

        $page = $this->decodePageScript(
            (string) $inertia->toResponse($this->makeRequest('users'), $this->makeResponse())->getBody(),
        );

        $this->assertTrue($page['encryptHistory']);
        $this->assertTrue($page['clearHistory']);
    }

    public function testRootViewOverridesTheViewTheHtmlComesFrom(): void
    {
        $inertia = new InertiaResponse('C', [], 'v1', 'missing-view');

        $this->assertSame($inertia, $inertia->rootView(self::SHELL_VIEW));

        $html = (string) $inertia->toResponse($this->makeRequest('users'), $this->makeResponse())->getBody();

        $this->assertStringContainsString('Inertia test shell', $html);
    }

    public function testAUrlResolverSetsThePageUrlInJsonAndHtml(): void
    {
        $resolver = static fn (IncomingRequest $request): string => '/custom';
        $inertia  = new InertiaResponse('C', [], 'v1', self::SHELL_VIEW, urlResolver: $resolver);

        $this->assertSame('/custom', $this->pageOf($inertia)['url']);

        $html = (string) $inertia->toResponse($this->makeRequest('users'), $this->makeResponse())->getBody();

        $this->assertSame('/custom', $this->decodePageScript($html)['url']);
    }

    public function testWithAddsASinglePropAndReturnsTheSameInstance(): void
    {
        $inertia = new InertiaResponse('C', [], 'v1', self::SHELL_VIEW);

        $this->assertSame($inertia, $inertia->with('a', 1));
        $this->assertSame(['a' => 1], $this->pageOf($inertia)['props']);
    }

    public function testWithAMapMergesAndALaterKeyWinsOverARenderProp(): void
    {
        $inertia = new InertiaResponse('C', ['a' => 0, 'keep' => true], 'v1', self::SHELL_VIEW);

        $this->assertSame($inertia, $inertia->with(['a' => 1, 'b' => 2]));
        $this->assertSame(['a' => 1, 'keep' => true, 'b' => 2], $this->pageOf($inertia)['props']);
    }

    public function testWithAPropertiesProviderExpandsItsProps(): void
    {
        $inertia  = new InertiaResponse('C', [], 'v1', self::SHELL_VIEW);
        $provider = new class () implements ProvidesInertiaProperties {
            public function toInertiaProperties(RenderContext $context): iterable
            {
                return ['fromProvider' => 'yes'];
            }
        };

        $this->assertSame($inertia, $inertia->with($provider));
        $this->assertSame(['fromProvider' => 'yes'], $this->pageOf($inertia)['props']);
    }

    public function testWithViewDataReachesTheRootViewAndNeverThePage(): void
    {
        $inertia = new InertiaResponse('C', [], 'v1', 'data_shell');

        $this->assertSame($inertia, $inertia->withViewData('title', 'T'));
        $this->assertSame($inertia, $inertia->withViewData(['meta' => 'm']));

        $html = $this->renderWithDataShell($inertia);

        $this->assertStringContainsString('<title>T</title><meta name="m">', $html);
        $this->assertArrayNotHasKey('title', $this->decodePageScript($html));
        $this->assertArrayNotHasKey('title', $this->pageOf($inertia));
    }

    public function testViewDataNamedPageNeverReplacesThePageObject(): void
    {
        $inertia = (new InertiaResponse('C', [], 'v1', 'data_shell'))->withViewData('page', ['component' => 'Hijacked']);

        $html = $this->renderWithDataShell($inertia);

        $this->assertSame('C', $this->decodePageScript($html)['component']);
    }

    public function testFluentCallsChainInAnyOrder(): void
    {
        $inertia = (new InertiaResponse('C', [], 'v1', 'missing-view'))
            ->withViewData('title', 'T')
            ->with('a', 1)
            ->rootView('data_shell')
            ->with(['b' => 2]);

        $html = $this->renderWithDataShell($inertia);

        $this->assertSame(['a' => 1, 'b' => 2], $this->decodePageScript($html)['props']);
        $this->assertStringContainsString('<title>T</title>', $html);
    }

    /**
     * Renders through a real view renderer rooted in a scratch directory holding a root view
     * that prints the `title` and `meta` view data next to the page script.
     */
    private function renderWithDataShell(InertiaResponse $inertia): string
    {
        $directory = sys_get_temp_dir() . '/ci4-inertia-views-' . bin2hex(random_bytes(4)) . '/';
        mkdir($directory);
        file_put_contents(
            $directory . 'data_shell.php',
            '<title><?= esc($title ?? "") ?></title><meta name="<?= esc($meta ?? "") ?>"><?= inertia_app($page) ?>',
        );
        Services::injectMock('renderer', new View(config('View'), $directory));

        try {
            return (string) $inertia->toResponse($this->makeRequest('users'), $this->makeResponse())->getBody();
        } finally {
            Services::reset(true);
            unlink($directory . 'data_shell.php');
            rmdir($directory);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function bigIntegerPage(mixed $value, bool $asJson = true): array
    {
        $inertia = (new InertiaResponse('Users/Index', ['value' => $value], 'v1', self::SHELL_VIEW))->preserveBigIntegers();

        if ($asJson) {
            return $this->pageOf($inertia);
        }

        return $this->decodePageScript((string) $inertia->toResponse($this->makeRequest('users'), $this->makeResponse())->getBody());
    }

    private static function plainObject(): object
    {
        return new class () {
            public int $id        = PHP_INT_MAX;
            public string $name   = 'x';
            protected int $hidden = PHP_INT_MAX;
            private int $secret   = PHP_INT_MAX;

            public function secret(): int
            {
                return $this->secret;
            }
        };
    }

    #[DataProvider('provideAnOptedInPageWrapsIntegersOutsideTheSafeRangeInBothWireForms')]
    public function testAnOptedInPageWrapsIntegersOutsideTheSafeRangeInBothWireForms(mixed $value, mixed $expected): void
    {
        foreach ([true, false] as $asJson) {
            $page = $this->bigIntegerPage($value, $asJson);

            $this->assertSame($expected, $page['props']['value']);
            $this->assertTrue($page['preserveBigIntegers']);
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function provideAnOptedInPageWrapsIntegersOutsideTheSafeRangeInBothWireForms(): iterable
    {
        $marker = static fn (string $digits): array => ['$bigint' => $digits];

        return [
            'largest safe integer stays an integer'   => [9007199254740991, 9007199254740991],
            'one past the safe range is wrapped'      => [9007199254740992, $marker('9007199254740992')],
            'smallest safe integer stays an integer'  => [-9007199254740991, -9007199254740991],
            'one below the safe range is wrapped'     => [-9007199254740992, $marker('-9007199254740992')],
            'PHP_INT_MAX is wrapped'                  => [PHP_INT_MAX, $marker((string) PHP_INT_MAX)],
            'PHP_INT_MIN is wrapped'                  => [PHP_INT_MIN, $marker((string) PHP_INT_MIN)],
            'a float is untouched'                    => [1.5, 1.5],
            'a numeric string is untouched'           => ['9007199254740993', '9007199254740993'],
            'a nested array is wrapped at depth'      => [['a' => ['b' => ['c' => PHP_INT_MAX]]], ['a' => ['b' => ['c' => $marker((string) PHP_INT_MAX)]]]],
            'a list is wrapped at depth'              => [[1, PHP_INT_MAX, [PHP_INT_MIN]], [1, $marker((string) PHP_INT_MAX), [$marker((string) PHP_INT_MIN)]]],
            'a JsonSerializable result is wrapped'    => [new BigIntegerSerializable(), ['id' => $marker((string) PHP_INT_MAX)]],
            'an int-backed enum is wrapped'           => [BigIntegerBackedEnum::Huge, $marker((string) PHP_INT_MAX)],
            'a small int-backed enum is its value'    => [BigIntegerBackedEnum::Small, 7],
            'a string-backed enum is its value'       => [BigIntegerStringEnum::Name, 'name'],
            'a DateTime keeps its own JSON shape'     => [new DateTimeImmutable('2024-01-02 03:04:05', new DateTimeZone('UTC')), ['date' => '2024-01-02 03:04:05.000000', 'timezone_type' => 3, 'timezone' => 'UTC']],
            'a plain object is wrapped by its fields' => [self::plainObject(), ['id' => $marker((string) PHP_INT_MAX), 'name' => 'x']],
        ];
    }

    public function testAStdClassHoldingABigIntegerStaysAJsonObjectWithTheMarkerInside(): void
    {
        $object        = new stdClass();
        $object->id    = PHP_INT_MAX;
        $object->empty = new stdClass();

        $inertia = (new InertiaResponse('Users/Index', ['value' => $object], 'v1', self::SHELL_VIEW))->preserveBigIntegers();
        $body    = (string) $inertia->toResponse($this->makeRequest('users', ['X-Inertia' => 'true']), $this->makeResponse())->getBody();

        $this->assertSame(
            '{"id":{"$bigint":"9223372036854775807"},"empty":{}}',
            json_encode(json_decode($body, false, flags: JSON_THROW_ON_ERROR)->props->value, JSON_THROW_ON_ERROR),
        );
    }

    public function testAPureEnumIsLeftForJsonEncodeToReject(): void
    {
        $this->expectException(FormatException::class);

        $this->bigIntegerPage(BigIntegerPureEnum::Case, true);
    }

    public function testAPlainObjectSharedByTwoBranchesIsWrappedInBoth(): void
    {
        $shared     = new stdClass();
        $shared->id = PHP_INT_MAX;
        $page       = $this->bigIntegerPage(['left' => $shared, 'right' => ['again' => $shared]]);
        $expected   = ['$bigint' => (string) PHP_INT_MAX];

        $this->assertSame($expected, $page['props']['value']['left']['id']);
        $this->assertSame($expected, $page['props']['value']['right']['again']['id']);
    }

    public function testAPlainObjectSharedByTwoPropertiesOfAnObjectIsWrappedInBoth(): void
    {
        $shared     = new stdClass();
        $shared->id = PHP_INT_MAX;
        $parent     = new stdClass();
        $parent->a  = $shared;
        $parent->b  = $shared;
        $page       = $this->bigIntegerPage($parent);
        $expected   = ['$bigint' => (string) PHP_INT_MAX];

        $this->assertSame($expected, $page['props']['value']['a']['id']);
        $this->assertSame($expected, $page['props']['value']['b']['id']);
    }

    public function testAnObjectExtendingAnInternalClassKeepsItsOwnJsonShape(): void
    {
        $date = new class ('2024-01-02 03:04:05', new DateTimeZone('UTC')) extends DateTimeImmutable {
            public int $big = PHP_INT_MAX;
        };
        $page = $this->bigIntegerPage($date);

        $this->assertSame(PHP_INT_MAX, $page['props']['value']['big']);
        $this->assertSame('2024-01-02 03:04:05.000000', $page['props']['value']['date']);
    }

    public function testASelfReferencingObjectDoesNotLoopAndItsReferenceIsLeftForTheJsonEncoder(): void
    {
        $loop       = new stdClass();
        $loop->self = $loop;
        $loop->id   = PHP_INT_MAX;

        $response = (new InertiaResponse('Users/Index', ['value' => $loop], 'v1', 'unused'))
            ->preserveBigIntegers()
            ->toResponse(self::inertiaRequest(), service('response', null, false));

        $value = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['props']['value'];

        $this->assertSame(['$bigint' => (string) PHP_INT_MAX], $value['id']);
        $this->assertSame(['self' => null, 'id' => PHP_INT_MAX], $value['self']);
    }

    private static function inertiaRequest(): IncomingRequest
    {
        $config  = config(AppConfig::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'users'), null, new UserAgent());
        $request->setHeader('X-Inertia', 'true');

        return $request;
    }

    public function testABigIntegerInFlashDataIsWrappedUnderFlash(): void
    {
        $this->mockSession();
        $factory = new ResponseFactory($this->makeRequest(), self::SHELL_VIEW, static fn (): string => 'v1');
        $factory->flash('total', PHP_INT_MAX);

        $page = $this->pageOf($factory->render('Users/Index', [])->preserveBigIntegers());

        $this->assertSame(['total' => ['$bigint' => (string) PHP_INT_MAX]], $page['flash']);
    }

    protected function tearDown(): void
    {
        config('Inertia')->strictProps = false;
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function flashedPage(string $key, mixed $value, bool $strictProps): array
    {
        $this->mockSession();
        config('Inertia')->strictProps = $strictProps;

        $factory = new ResponseFactory($this->makeRequest(), self::SHELL_VIEW, static fn (): string => 'v1');
        $factory->flash($key, $value);

        return $this->pageOf($factory->render('Users/Index', []));
    }

    #[DataProvider('provideStrictPropsAcceptsFlashOfPlainDataAsWithTheFlagOff')]
    public function testStrictPropsAcceptsFlashOfPlainDataAsWithTheFlagOff(mixed $value): void
    {
        $off = $this->flashedPage('k', $value, false)['flash'];
        $on  = $this->flashedPage('k', $value, true)['flash'];

        $this->assertSame($off, $on);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideStrictPropsAcceptsFlashOfPlainDataAsWithTheFlagOff(): iterable
    {
        yield 'a scalar' => ['Saved'];

        yield 'an array' => [['a' => [1, 2], 'b' => null]];

        yield 'a stdClass of scalars' => [(object) ['a' => 1, 'b' => 'two']];
    }

    public function testStrictPropsRefusesFlashOfAnEntityAtItsKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Inertia flash value "flash.user" must hold only null, scalars, arrays, stdClass objects and backed enums that are not JsonSerializable when strictProps is on; ' . Entity::class . ' given.');

        $this->flashedPage('user', new Entity(['token' => 's3cr3t-token']), true);
    }

    public function testStrictPropsRefusesAFlashStdClassThatRefersToItselfAtItsFlashPath(): void
    {
        $bag       = new stdClass();
        $bag->self = $bag;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Inertia flash value "flash.data.self" refers back to an enclosing stdClass, which strictProps cannot check.');

        $this->flashedPage('data', $bag, true);
    }

    public function testStrictPropsRefusesAnObjectNestedInFlashDataAtItsDotPath(): void
    {
        try {
            $this->flashedPage('data', [new Entity(['token' => 's3cr3t-token'])], true);
            $this->fail('A nested flash object must be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(
                'Inertia flash value "flash.data.0" must hold only null, scalars, arrays, stdClass objects and backed enums that are not JsonSerializable when strictProps is on; ' . Entity::class . ' given.',
                $e->getMessage(),
            );
            $this->assertStringNotContainsString('s3cr3t-token', $e->getMessage());
        }
    }

    public function testWithStrictPropsOffFlashOfAnEntityRendersAsItsAttributes(): void
    {
        $page = $this->flashedPage('user', new Entity(['token' => 'abc']), false);

        $this->assertSame(['user' => ['token' => 'abc']], $page['flash']);
    }

    public function testThePreserveFlagComesRightAfterTheMetadataAndBeforeClearHistory(): void
    {
        $inertia = (new InertiaResponse(
            'Users/Index',
            ['stats' => new DeferProp(static fn (): int => 1)],
            'v1',
            self::SHELL_VIEW,
            encryptHistory: true,
            clearHistory: true,
        ))->preserveBigIntegers();

        $keys = array_keys($this->pageOf($inertia));

        $this->assertSame(['component', 'props', 'url', 'version', 'deferredProps', 'preserveBigIntegers', 'clearHistory', 'encryptHistory'], $keys);
    }

    public function testBigIntegersAreSentRawAndTheKeyIsAbsentUnlessAskedFor(): void
    {
        $page = $this->pageOf(new InertiaResponse('Users/Index', ['value' => PHP_INT_MAX], 'v1', self::SHELL_VIEW));

        $this->assertSame(PHP_INT_MAX, $page['props']['value']);
        $this->assertArrayNotHasKey('preserveBigIntegers', $page);
    }

    public function testTheConstructorFlagOptsAPageIn(): void
    {
        $inertia = new InertiaResponse('Users/Index', ['value' => PHP_INT_MAX], 'v1', self::SHELL_VIEW, preserveBigIntegers: true);

        $this->assertSame(['$bigint' => (string) PHP_INT_MAX], $this->pageOf($inertia)['props']['value']);
    }

    public function testPreserveBigIntegersFalseTurnsAnOptedInPageOff(): void
    {
        $inertia = new InertiaResponse('Users/Index', ['value' => PHP_INT_MAX], 'v1', self::SHELL_VIEW, preserveBigIntegers: true);
        $inertia->preserveBigIntegers(false);
        $page = $this->pageOf($inertia);

        $this->assertSame(PHP_INT_MAX, $page['props']['value']);
        $this->assertArrayNotHasKey('preserveBigIntegers', $page);
    }

    public function testPreserveBigIntegersReturnsTheSameResponse(): void
    {
        $inertia = new InertiaResponse('Users/Index', [], 'v1', self::SHELL_VIEW);

        $this->assertSame($inertia, $inertia->preserveBigIntegers());
        $this->assertSame($inertia, $inertia->preserveBigIntegers(false));
    }
}

enum BigIntegerBackedEnum: int
{
    case Huge  = 9223372036854775807;
    case Small = 7;
}

enum BigIntegerStringEnum: string
{
    case Name = 'name';
}

enum BigIntegerPureEnum
{
    case Case;
}

final class BigIntegerSerializable implements JsonSerializable
{
    /**
     * @return array<string, int>
     */
    public function jsonSerialize(): array
    {
        return ['id' => PHP_INT_MAX];
    }
}
