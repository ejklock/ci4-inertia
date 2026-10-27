<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use CodeIgniter\Test\CIUnitTestCase;
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
}
