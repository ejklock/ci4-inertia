<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Testing;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\TestResponse;
use Config\App as AppConfig;
use Ejklock\Ci4Inertia\InertiaResponse;
use Ejklock\Ci4Inertia\Testing\AssertableInertia;
use Ejklock\Ci4Inertia\Testing\InertiaTestTrait;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Verifies `InertiaTestTrait`: reading the page from the JSON and the HTML wire forms,
 * failing clearly on a response that carries no page, and the request headers it builds.
 *
 * @internal
 */
final class InertiaTestTraitTest extends CIUnitTestCase
{
    use InertiaTestTrait;

    private const SHELL_VIEW = 'Ejklock\Ci4InertiaTestSupport\Views\shell';

    /**
     * @param array<string, mixed> $props
     */
    private function inertiaResult(bool $asJson, array $props = ['title' => 'Users', 'users' => [['name' => 'Ada']]]): TestResponse
    {
        $config  = config(AppConfig::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'users'), null, new UserAgent());

        if ($asJson) {
            $request->setHeader('X-Inertia', 'true');
        }

        $response = (new InertiaResponse('Users/Index', $props, 'v1', self::SHELL_VIEW))
            ->toResponse($request, service('response', null, false));

        return new TestResponse($response);
    }

    private function plainResult(string $body, string $contentType): TestResponse
    {
        $response = service('response', null, false);
        $response->setBody($body)->setContentType($contentType);

        return new TestResponse($response);
    }

    public function testReadsPageFromInertiaJsonResponse(): void
    {
        $page = $this->assertInertia($this->inertiaResult(true));

        $page->component('Users/Index')->url('/users')->version('v1')->where('title', 'Users')->has('users.0.name');
        $this->assertSame('Users/Index', $page->page()['component']);
    }

    public function testReadsSamePageFromHtmlShell(): void
    {
        $page = $this->assertInertia($this->inertiaResult(false));

        $this->assertSame($page, $page->component('Users/Index')->url('/users')->version('v1')->where('title', 'Users')->has('users', 1));
    }

    public function testMarkupCharactersInPropsAreDecodedToTheOriginalString(): void
    {
        $original = '<script>alert("x") & \'y\'</script>';

        $page = $this->assertInertia($this->inertiaResult(false, ['note' => $original]));

        $this->assertSame($page, $page->where('note', $original));
    }

    public function testNonAsciiPropsDecodeWhenTheDocumentDeclaresNoCharset(): void
    {
        $page = $this->assertInertia($this->plainResult(
            '<html><body><script data-page="app" type="application/json">{"component":"A","props":{"word":"ação"},"url":"/","version":""}</script><div id="app"></div></body></html>',
            'text/html',
        ));

        $this->assertSame($page, $page->where('word', 'ação'));
    }

    public function testNonAsciiPropsDecodeWhenTheDocumentDeclaresUtf8(): void
    {
        $page = $this->assertInertia($this->plainResult(
            '<html><head><meta charset="utf-8"></head><body><script data-page="app" type="application/json">{"component":"A","props":{"word":"ação"},"url":"/","version":""}</script><div id="app"></div></body></html>',
            'text/html',
        ));

        $this->assertSame($page, $page->where('word', 'ação'));
    }

    public function testPlainJsonWithoutInertiaHeaderIsNotAnInertiaResponse(): void
    {
        $json = (string) json_encode(['component' => 'A', 'props' => [], 'url' => '/', 'version' => '']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('not an Inertia response');

        $this->assertInertia($this->plainResult($json, 'application/json'));
    }

    public function testHtmlWithoutThePageScriptFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('script');

        $this->assertInertia($this->plainResult('<html><body><div id="app"></div></body></html>', 'text/html'));
    }

    public function testAScriptOfAnotherTypeIsNotAnInertiaResponse(): void
    {
        $this->assertNotInertiaResponse($this->plainResult(
            '<script data-page="app" type="text/javascript">{"component":"A","props":{},"url":"/","version":""}</script>',
            'text/html',
        ));
    }

    public function testTheOldDataPageAttributeIsNotAnInertiaResponse(): void
    {
        $page = '{"component":"A","props":{},"url":"/","version":""}';

        $this->assertNotInertiaResponse($this->plainResult('<div id="app" data-page="' . esc($page, 'attr') . '"></div>', 'text/html'));
    }

    public function testAnEmptyBodyFailureNamesTheHeaderAndTheScriptTag(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('X-Inertia header');
        $this->expectExceptionMessage('script');

        $this->assertInertia($this->plainResult('', 'text/html'));
    }

    private function assertNotInertiaResponse(TestResponse $result): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('not an Inertia response');

        $this->assertInertia($result);
    }

    public function testEmptyBodyFailsAsNotAnInertiaResponse(): void
    {
        $this->assertNotInertiaResponse($this->plainResult('', 'text/html'));
    }

    public function testWhitespaceOnlyBodyFailsAsNotAnInertiaResponse(): void
    {
        $this->assertNotInertiaResponse($this->plainResult("  \n", 'text/html'));
    }

    public function testResponseWithoutBodyFailsAsNotAnInertiaResponse(): void
    {
        $this->assertNotInertiaResponse(new TestResponse(service('response', null, false)));
    }

    public function testRedirectFailsAsNotAnInertiaResponse(): void
    {
        $this->assertNotInertiaResponse(new TestResponse(redirect()->to('/login')));
    }

    public function testLocationConflictWithoutBodyFailsAsNotAnInertiaResponse(): void
    {
        $response = service('response', null, false)->setStatusCode(409)->setHeader('X-Inertia-Location', '/x');

        $this->assertNotInertiaResponse(new TestResponse($response));
    }

    public function testDataPageWithInvalidJsonFailsAsAssertion(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('JSON');

        $this->assertInertia($this->plainResult('<script data-page="app" type="application/json">{not json</script>', 'text/html'));
    }

    public function testInertiaJsonBodyThatIsNotAnObjectFailsAsAssertion(): void
    {
        $response = service('response', null, false);
        $response->setHeader('X-Inertia', 'true')->setBody('"scalar"');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('JSON');

        $this->assertInertia(new TestResponse($response));
    }

    public function testPageWithUnknownKeyIsAcceptedAndVisible(): void
    {
        $page = json_encode([
            'component'     => 'A',
            'props'         => [],
            'url'           => '/',
            'version'       => '',
            'deferredProps' => ['default' => ['stats']],
        ]);

        $result = $this->plainResult('<script data-page="app" type="application/json">' . $page . '</script><div id="app"></div>', 'text/html');

        $this->assertSame(['default' => ['stats']], $this->assertInertia($result)->page()['deferredProps']);
    }

    public function testCallbackReceivesTheAssertableOnceAndItIsReturned(): void
    {
        $received = [];

        $returned = $this->assertInertia($this->inertiaResult(true), static function (AssertableInertia $page) use (&$received): void {
            $received[] = $page;
        });

        $this->assertCount(1, $received);
        $this->assertSame($returned, $received[0]);
    }

    public function testFailureInsideCallbackPropagates(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Posts/Index');

        $this->assertInertia($this->inertiaResult(true), static function (AssertableInertia $page): void {
            $page->component('Posts/Index');
        });
    }

    public function testInertiaHeadersCarryTheVersionAndMergeExtras(): void
    {
        $this->assertSame(
            ['X-Inertia' => 'true', 'X-Inertia-Version' => 'v1', 'X-Inertia-Partial-Data' => 'users'],
            $this->inertiaHeaders('v1', ['X-Inertia-Partial-Data' => 'users']),
        );
        $this->assertSame(['X-Inertia' => 'true', 'X-Inertia-Version' => ''], $this->inertiaHeaders());
    }

    public function testExtraHeadersCannotOverrideTheInertiaHeaders(): void
    {
        $headers = $this->inertiaHeaders('v1', ['X-Inertia' => 'false', 'X-Inertia-Version' => 'other']);

        $this->assertSame(['X-Inertia' => 'true', 'X-Inertia-Version' => 'v1'], $headers);
    }
}
