<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use CodeIgniter\Config\BaseService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App as AppConfig;
use Config\Services;
use Ejklock\Ci4Inertia\Inertia;
use Ejklock\Ci4Inertia\Ssr\Gateway;
use Ejklock\Ci4Inertia\Ssr\Response;
use JsonException;
use stdClass;

/**
 * Verifies the `inertia_app()` view helper: the exact script tag and mount element it prints,
 * the escaping that keeps page data from closing the tag, and the id attribute escaping.
 *
 * @internal
 */
final class InertiaHelperTest extends CIUnitTestCase
{
    private int $gatewayCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        helper('inertia');
    }

    /**
     * @param array<string, mixed>|object $props
     *
     * @return array<string, mixed>
     */
    private function page(array|object $props = []): array
    {
        return ['component' => 'Users/Index', 'props' => $props, 'url' => '/users', 'version' => 'v1'];
    }

    private function scriptJson(string $html): string
    {
        $this->assertSame(1, preg_match('#^<script data-page="[^"]*" type="application/json">(.*)</script><div id="[^"]*"></div>$#s', $html, $matches));

        return $matches[1];
    }

    public function testPrintsTheScriptTagFollowedByTheMountElement(): void
    {
        $page = $this->page(['title' => 'Users']);

        $html = inertia_app($page);

        $this->assertStringStartsWith('<script data-page="app" type="application/json">', $html);
        $this->assertStringEndsWith('</script><div id="app"></div>', $html);
        $this->assertSame($page, json_decode($this->scriptJson($html), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAScriptClosingSequenceInAPropCannotEndTheTagEarly(): void
    {
        $original = '</script><script>alert(1)</script>';

        $html = inertia_app($this->page(['note' => $original]));

        $this->assertSame(1, substr_count($html, '</script>'));
        $this->assertStringNotContainsStringIgnoringCase('</script', $this->scriptJson($html));

        $decoded = json_decode($this->scriptJson($html), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($original, $decoded['props']['note']);
    }

    public function testAngleBracketsAloneAreEscapedAndDecodeBack(): void
    {
        $json = $this->scriptJson(inertia_app($this->page(['lt' => '<', 'gt' => '>'])));

        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('>', $json);

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['lt' => '<', 'gt' => '>'], $decoded['props']);
    }

    public function testAnIdNamesBothTheScriptAndTheMountElement(): void
    {
        $html = inertia_app($this->page(), 'root');

        $this->assertStringStartsWith('<script data-page="root" type="application/json">', $html);
        $this->assertStringEndsWith('</script><div id="root"></div>', $html);
    }

    public function testAnIdIsAttributeEscapedInBothPlaces(): void
    {
        $html = inertia_app($this->page(), 'a"b<c');

        $this->assertStringStartsWith('<script data-page="a&quot;b&lt;c" type="application/json">', $html);
        $this->assertStringEndsWith('</script><div id="a&quot;b&lt;c"></div>', $html);
    }

    public function testAnEmptyObjectPropsMapStaysAJsonObject(): void
    {
        $json = $this->scriptJson(inertia_app($this->page(new stdClass())));

        $this->assertStringContainsString('"props":{}', $json);
        $this->assertInstanceOf(stdClass::class, json_decode($json, false, flags: JSON_THROW_ON_ERROR)->props);
    }

    public function testNonAsciiTextRoundTrips(): void
    {
        $json = $this->scriptJson(inertia_app($this->page(['word' => 'ação'])));

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ação', $decoded['props']['word']);
    }

    public function testAValueThatCannotBeEncodedThrows(): void
    {
        $this->expectException(JsonException::class);

        inertia_app($this->page(['bad' => "\xB1\x31"]));
    }

    public function testInertiaWithoutArgumentsReturnsTheSharedFactory(): void
    {
        $this->assertSame(service('inertia'), inertia());
    }

    public function testInertiaWithAComponentRendersIt(): void
    {
        $response = inertia('Users/Index', ['a' => 1]);

        $request = $this->makeRequest(['X-Inertia' => 'true']);
        $page    = json_decode((string) $response->toResponse($request, service('response', null, false))->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Users/Index', $page['component']);
        $this->assertSame(['a' => 1], $page['props']);
    }

    public function testInertiaWithAnEmptyComponentReturnsTheFactory(): void
    {
        $this->assertSame(service('inertia'), inertia(''));
    }

    public function testInertiaWithAZeroStringComponentReturnsTheFactory(): void
    {
        $this->assertSame(service('inertia'), inertia('0'));
    }

    public function testInertiaLocationAnswers409OnAnInertiaRequest(): void
    {
        $this->useRequest(['X-Inertia' => 'true']);

        $response = inertia_location('/x');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('/x', $response->getHeaderLine('X-Inertia-Location'));
    }

    public function testInertiaLocationRedirectsOtherwise(): void
    {
        $this->useRequest();

        $response = inertia_location('/x');

        $this->assertStringEndsWith('/x', $response->getHeaderLine('Location'));
        $this->assertFalse($response->hasHeader('X-Inertia-Location'));
    }

    public function testInertiaLocationAcceptsARedirectResponse(): void
    {
        $this->useRequest(['X-Inertia' => 'true']);

        $response = inertia_location(redirect()->to('/y'));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringEndsWith('/y', $response->getHeaderLine('X-Inertia-Location'));
    }

    public function testTheHeadHelperIsDefinedByLoadingTheHelper(): void
    {
        $this->assertTrue(function_exists('inertia_head'));
    }

    public function testBothHelpersAreDefinedByLoadingTheHelper(): void
    {
        $this->assertTrue(function_exists('inertia'));
        $this->assertTrue(function_exists('inertia_location'));
    }

    /**
     * @param array<string, string> $headers
     */
    private function makeRequest(array $headers = []): IncomingRequest
    {
        $config  = config(AppConfig::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'users'), null, new UserAgent());

        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }

        return $request;
    }

    /**
     * @param array<string, string> $headers
     */
    private function useRequest(array $headers = []): void
    {
        Services::injectMock('request', $this->makeRequest($headers));
        Services::resetSingle('inertia');
    }

    private function injectSsrResponse(?Response $response): void
    {
        $this->gatewayCalls = 0;

        BaseService::injectMock('inertiaSsrGateway', new class ($response, $this) implements Gateway {
            public function __construct(private readonly ?Response $response, private readonly InertiaHelperTest $test)
            {
            }

            public function dispatch(array $page): ?Response
            {
                $this->test->countGatewayCall();

                return $this->response;
            }
        });
    }

    public function countGatewayCall(): void
    {
        $this->gatewayCalls++;
    }

    public function testInertiaAppPrintsOnlyTheServerRenderedBody(): void
    {
        $this->injectSsrResponse(new Response('<title>T</title>', '<div id="app">ssr</div>'));

        $this->assertSame('<div id="app">ssr</div>', inertia_app($this->page()));
    }

    public function testInertiaHeadPrintsTheServerRenderedHeadAndIgnoresTheFallback(): void
    {
        $this->injectSsrResponse(new Response('<title>T</title>', '<div id="app">ssr</div>'));

        $this->assertSame('<title>T</title>', inertia_head($this->page()));
        $this->assertSame('<title>T</title>', inertia_head($this->page(), '<title>F</title>'));
    }

    public function testWithoutAServerRenderTheScriptTagAndEmptyHeadAreKept(): void
    {
        $this->injectSsrResponse(null);
        $page = $this->page();

        $this->assertStringStartsWith('<script data-page="app" type="application/json">', inertia_app($page));
        $this->assertStringEndsWith('</script><div id="root"></div>', inertia_app($page, 'root'));
        $this->assertSame('', inertia_head($page));
    }

    public function testWithoutAServerRenderTheHeadFallbackIsPrintedUnescaped(): void
    {
        $this->injectSsrResponse(null);

        $this->assertSame('<title>F</title>', inertia_head($this->page(), '<title>F</title>'));
    }

    public function testHeadThenAppSendTheSamePageToTheGatewayOnce(): void
    {
        $this->injectSsrResponse(new Response('h', 'b'));
        $page = $this->page();

        inertia_head($page);
        inertia_app($page);

        $this->assertSame(1, $this->gatewayCalls);
    }

    public function testRootViewPrintsTheServerRenderedHeadAndBody(): void
    {
        $this->injectSsrResponse(new Response('<title>SSR head</title>', '<div id="app">SSR body</div>'));

        $html = $this->renderSsrShell();

        $this->assertStringContainsString('<title>SSR head</title>', $html);
        $this->assertStringContainsString('<div id="app">SSR body</div>', $html);
        $this->assertStringNotContainsString('data-page', $html);
        $this->assertStringNotContainsString('Fallback head', $html);
        $this->assertSame(1, $this->gatewayCalls);
    }

    public function testRootViewKeepsTheScriptTagAndHeadFallbackWithoutABundle(): void
    {
        $html = $this->renderSsrShell();

        $this->assertStringContainsString('<title>Fallback head</title>', $html);
        $this->assertStringContainsString('<script data-page="app" type="application/json">', $html);
        $this->assertStringContainsString('"component":"Users/Index"', $html);
    }

    private function renderSsrShell(): string
    {
        $this->useRequest();
        Inertia::setRootView('Ejklock\\Ci4InertiaTestSupport\\Views\\ssr_shell');

        return (string) Inertia::render('Users/Index')
            ->toResponse($this->makeRequest(), service('response', null, false))
            ->getBody();
    }

    protected function tearDown(): void
    {
        BaseService::resetSingle('inertiaSsrState');
        BaseService::resetSingle('inertiaSsrGateway');
        Services::reset(true);

        parent::tearDown();
    }
}
