<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Testing;

use Closure;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Ejklock\Ci4Inertia\Support\Header;
use Ejklock\Ci4Inertia\Support\SessionKey;
use JsonException;
use PHPUnit\Framework\Assert;

/**
 * For a test case: assert on the Inertia page a response carried, whether the Inertia JSON
 * (`X-Inertia: true`) or the HTML first-visit shell holding the page in a
 * `<script data-page type="application/json">` element.
 *
 * Following a page with `reload()` also needs the test case to use `FeatureTestTrait`, whose
 * request methods the reload goes through.
 *
 * @property array<string, list<string>|string> $headers
 *
 * @method TestResponse get(string $path, ?array<array-key, mixed> $params = null)
 * @method static       withHeaders(array<string, list<string>|string> $headers = [])
 */
trait InertiaTestTrait
{
    /**
     * Lower-cased names of the headers that make a request a partial reload; a reload never
     * inherits them from an earlier visit.
     */
    private const PARTIAL_HEADERS = ['x-inertia-partial-component', 'x-inertia-partial-data', 'x-inertia-partial-except', 'x-inertia-reset'];

    /**
     * @param (Closure(AssertableInertia): mixed)|null $callback
     */
    protected function assertInertia(TestResponse $result, ?Closure $callback = null): AssertableInertia
    {
        $assertable = AssertableInertia::fromPageWithReloader($this->inertiaPage($result), $this->inertiaReloader());

        if ($callback instanceof Closure) {
            $callback($assertable);
        }

        return $assertable;
    }

    /**
     * The decoded page object of a response, from the Inertia JSON or the HTML first-visit shell.
     *
     * @return array<string, mixed>
     */
    protected function inertiaPage(TestResponse $result): array
    {
        $body = (string) $result->response()->getBody();

        $json = $result->response()->getHeaderLine('X-Inertia') === 'true'
            ? $body
            : $this->inertiaPageJsonFromHtml($body);

        return $this->decodeInertiaPage($json);
    }

    /**
     * One prop of the page a response carried (a dot path reaches a nested value), or every
     * prop when no key is given. A missing key fails the test.
     */
    protected function inertiaProp(TestResponse $result, ?string $key = null): mixed
    {
        $page = AssertableInertia::fromPage($this->inertiaPage($result));

        return $key === null ? $page->page()['props'] : $page->prop($key);
    }

    /**
     * Asserts the Inertia flash data the session holds after a request that redirected has the
     * key (a dot path reaches a nested value) and, when an expected value is given, holds it
     * strictly. The session is read as it is, never started.
     */
    protected function assertInertiaFlash(TestResponse $result, string $key, mixed $expected = null): void
    {
        if (func_num_args() > 2) {
            AssertableInertia::assertFlashHas($this->inertiaSessionFlash(), $key, $expected);
        } else {
            AssertableInertia::assertFlashHas($this->inertiaSessionFlash(), $key);
        }
    }

    /**
     * Asserts the Inertia flash data the session holds after a request that redirected does not
     * have the key.
     */
    protected function assertInertiaFlashMissing(TestResponse $result, string $key): void
    {
        AssertableInertia::assertFlashMissing($this->inertiaSessionFlash(), $key);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function inertiaSessionFlash(): array
    {
        $flash = $_SESSION[SessionKey::FLASH_DATA] ?? [];

        return is_array($flash) ? $flash : [];
    }

    /**
     * Request headers of an Inertia visit, for `withHeaders()`. The two Inertia headers win over `$extra`.
     *
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    protected function inertiaHeaders(string $version = '', array $extra = []): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => $version] + $extra;
    }

    /**
     * The request handle `AssertableInertia` reloads through, or null when the test case has no
     * FeatureTestTrait to issue a request with. The reload is a fresh request: it sends the headers
     * the test had set except the partial-reload ones of an earlier visit, overlaid with the
     * reload's own, and puts the test's headers back afterwards, even when the request throws.
     *
     * @return (Closure(string, array<string, string>): array<string, mixed>)|null
     *
     * @psalm-suppress UndefinedMethod get() and withHeaders() come from FeatureTestTrait, whose presence is checked at runtime
     */
    private function inertiaReloader(): ?Closure
    {
        if (! in_array(FeatureTestTrait::class, class_uses_recursive($this), true)) {
            return null;
        }

        return function (string $url, array $headers): array {
            $previous = $this->headers;
            $kept     = array_filter(
                $previous,
                static fn (string $name): bool => ! in_array(strtolower($name), self::PARTIAL_HEADERS, true),
                ARRAY_FILTER_USE_KEY,
            );

            try {
                $this->withHeaders([...$kept, Header::INERTIA => 'true', ...$headers]);

                return $this->inertiaPage($this->get($url));
            } finally {
                $this->withHeaders($previous);
            }
        };
    }

    private function inertiaPageJsonFromHtml(string $html): string
    {
        $node = null;

        if (trim($html) !== '') {
            $previous = libxml_use_internal_errors(true);

            try {
                $document = new DOMDocument();
                // The prefix makes libxml read UTF-8 even when the document declares no charset.
                $document->loadHTML('<?xml encoding="UTF-8">' . $html);
                $node = (new DOMXPath($document))->query('//script[@data-page][@type="application/json"]')->item(0);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        if (! $node instanceof DOMElement) {
            Assert::fail('The response is not an Inertia response: it has no X-Inertia header and no script tag with a data-page attribute and type application/json.');
        }

        return $node->textContent;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeInertiaPage(string $json): array
    {
        try {
            $page = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Assert::fail('The Inertia page is not valid JSON: ' . $e->getMessage());
        }

        if (! is_array($page)) {
            Assert::fail('The Inertia page is not a JSON object.');
        }

        return $page;
    }
}
