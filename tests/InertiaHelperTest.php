<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App as AppConfig;
use Config\Services;
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

    protected function tearDown(): void
    {
        Services::reset(true);

        parent::tearDown();
    }
}
