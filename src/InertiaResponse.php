<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Ejklock\Ci4Inertia\Support\Header;

/**
 * Produces the Inertia page object for one render and picks the wire
 * representation: the JSON body an Inertia-aware request receives, or the HTML
 * shell that embeds the same object in a `script` tag, printed by the `inertia_app()`
 * helper, for a first visit or a hard reload. An instance is immutable and single-use — build one per `render()`
 * call.
 */
final readonly class InertiaResponse
{
    /**
     * @param string               $component      The client-side component name Inertia
     *                                             mounts, e.g. `Users/Index`.
     * @param array<string, mixed> $props          The declared props, keyed by name. A value
     *                                             may be a closure or an invokable object, called
     *                                             only when the prop is sent; an `OptionalProp`
     *                                             to leave it out of a full render, a `DeferProp`
     *                                             to leave it out of a full render and have the
     *                                             client fetch it afterwards, a `MergeProp` to
     *                                             have the client combine it with its current
     *                                             value, or an `AlwaysProp` to bypass partial
     *                                             reload filtering; any other value is sent as-is.
     * @param string               $version        The asset version echoed on the page object,
     *                                             compared by the client against the version
     *                                             it booted with to detect stale assets.
     * @param string               $rootView       The root view rendered for a non-Inertia
     *                                             visit.
     * @param bool                 $encryptHistory Whether the page object asks the client to
     *                                             encrypt this page's history state.
     * @param bool                 $clearHistory   Whether the page object asks the client to
     *                                             discard its encrypted history by rotating the key.
     */
    public function __construct(
        private string $component,
        private array $props,
        private string $version,
        private string $rootView,
        private bool $encryptHistory = false,
        private bool $clearHistory = false,
    ) {
    }

    /**
     * Builds the page object and returns it as JSON or as an HTML shell, chosen solely by
     * whether the request carries the `X-Inertia` header — the header selects a wire
     * representation, never a permission; authorization is decided upstream by
     * the filter chain before this method runs.
     *
     * @param IncomingRequest   $request  The current request; only its headers, path and
     *                                    query string are read.
     * @param ResponseInterface $response The response to populate and return.
     *
     * @return ResponseInterface The same response, carrying either the JSON page object
     *                           with `X-Inertia: true`, or the rendered HTML shell.
     */
    public function toResponse(IncomingRequest $request, ResponseInterface $response): ResponseInterface
    {
        $page = $this->buildPageObject($request);

        return $this->isInertiaRequest($request)
            ? $this->asJson($response, $page)
            : $this->asHtml($response, $page);
    }

    /**
     * Assembles the page object: the keys `component`, `props`, `url` and `version`, then the
     * metadata the props resolver collected (`deferredProps`, `mergeProps`, `prependProps`,
     * `deepMergeProps`, `matchPropsOn`) when it is not empty, then `encryptHistory` and
     * `clearHistory` when each holds — the shape both `asJson()` and `asHtml()` serialize verbatim.
     *
     * @return array<string, mixed>
     */
    private function buildPageObject(IncomingRequest $request): array
    {
        [$props, $metadata] = (new PropsResolver($request, $this->component))->resolve($this->props);

        $page = [
            'component' => $this->component,
            'props'     => $props,
            'url'       => $this->currentUrl($request),
            'version'   => $this->version,
            ...$metadata,
        ];

        if ($this->encryptHistory) {
            $page['encryptHistory'] = true;
        }

        if ($this->clearHistory) {
            $page['clearHistory'] = true;
        }

        return $page;
    }

    private function isInertiaRequest(IncomingRequest $request): bool
    {
        return $request->hasHeader(Header::INERTIA);
    }

    /**
     * Builds the page object's `url`: an absolute path with a guaranteed leading slash,
     * plus the query string when present. Never includes scheme or host — the page
     * object's `url` is relative by contract.
     */
    private function currentUrl(IncomingRequest $request): string
    {
        $path  = '/' . ltrim($request->getPath(), '/');
        $query = $request->getUri()->getQuery();

        return $query === '' ? $path : $path . '?' . $query;
    }

    /**
     * Serializes the page object as the JSON response an Inertia-aware client expects,
     * marking it with the `X-Inertia: true` header the protocol contract requires.
     *
     * @param array<string, mixed> $page The page object built by `buildPageObject()`.
     */
    private function asJson(ResponseInterface $response, array $page): ResponseInterface
    {
        return $response
            ->setStatusCode(200)
            ->setHeader(Header::INERTIA, 'true')
            ->setJSON($page);
    }

    /**
     * Renders the root view with the page object in its `page` variable; the view prints it
     * as a JSON script tag through `inertia_app()` — the entry point for a first visit or a
     * hard reload.
     *
     * @param array<string, mixed> $page The page object built by `buildPageObject()`.
     */
    private function asHtml(ResponseInterface $response, array $page): ResponseInterface
    {
        helper('inertia');

        $html = view($this->rootView, ['page' => $page]);

        return $response->setBody($html);
    }
}
