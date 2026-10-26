<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Testing;

use Closure;
use CodeIgniter\Test\TestResponse;
use DOMDocument;
use DOMElement;
use DOMXPath;
use JsonException;
use PHPUnit\Framework\Assert;

/**
 * For a test case: assert on the Inertia page a response carried, whether the Inertia JSON
 * (`X-Inertia: true`) or the HTML first-visit shell holding the page in a `data-page` attribute.
 */
trait InertiaTestTrait
{
    /**
     * @param (Closure(AssertableInertia): mixed)|null $callback
     */
    protected function assertInertia(TestResponse $result, ?Closure $callback = null): AssertableInertia
    {
        $body = (string) $result->response()->getBody();

        $json = $result->response()->getHeaderLine('X-Inertia') === 'true'
            ? $body
            : $this->inertiaPageJsonFromHtml($body);

        $assertable = AssertableInertia::fromPage($this->decodeInertiaPage($json));

        if ($callback instanceof Closure) {
            $callback($assertable);
        }

        return $assertable;
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

    private function inertiaPageJsonFromHtml(string $html): string
    {
        $node = null;

        if (trim($html) !== '') {
            $previous = libxml_use_internal_errors(true);

            try {
                $document = new DOMDocument();
                $document->loadHTML($html);
                $node = (new DOMXPath($document))->query('//*[@data-page]')->item(0);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        if (! $node instanceof DOMElement) {
            Assert::fail('The response is not an Inertia response: it has no X-Inertia header and no data-page attribute.');
        }

        return $node->getAttribute('data-page');
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
