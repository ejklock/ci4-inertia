<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use BackedEnum;
use Closure;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Ejklock\Ci4Inertia\Config\Services;
use Ejklock\Ci4Inertia\Support\Header;
use UnitEnum;

/**
 * Produces the Inertia page object for one render and picks the wire
 * representation: the JSON body an Inertia-aware request receives, or the HTML
 * shell that embeds the same object in a `script` tag, printed by the `inertia_app()`
 * helper, for a first visit or a hard reload. An instance is single-use — build one per `render()`
 * call — and `with()`, `withViewData()` and `rootView()` change it before `toResponse()`.
 */
final class InertiaResponse
{
    /**
     * @var array<string, mixed>
     */
    private array $viewData = [];

    /**
     * @param string                                  $component        The client-side component name Inertia
     *                                                                  mounts, e.g. `Users/Index`.
     * @param array<array-key, mixed>                 $props            The declared props, keyed by name. A value
     *                                                                  may be a closure or an invokable object, called
     *                                                                  only when the prop is sent; an `OptionalProp`
     *                                                                  to leave it out of a full render, a `DeferProp`
     *                                                                  to leave it out of a full render and have the
     *                                                                  client fetch it afterwards, a `MergeProp` to
     *                                                                  have the client combine it with its current
     *                                                                  value, or an `AlwaysProp` to bypass partial
     *                                                                  reload filtering; any other value is sent as-is.
     * @param string                                  $version          The asset version echoed on the page object,
     *                                                                  compared by the client against the version
     *                                                                  it booted with to detect stale assets.
     * @param string                                  $rootView         The root view rendered for a non-Inertia
     *                                                                  visit.
     * @param bool                                    $encryptHistory   Whether the page object asks the client to
     *                                                                  encrypt this page's history state.
     * @param bool                                    $clearHistory     Whether the page object asks the client to
     *                                                                  discard its encrypted history by rotating the key.
     * @param array<array-key, mixed>                 $sharedProps      The shared props, kept apart from the page
     *                                                                  props so the page can list their top-level keys
     *                                                                  under `sharedProps`; a page prop with the same
     *                                                                  key wins.
     * @param (Closure(IncomingRequest): string)|null $urlResolver      Computes the page object's `url`
     *                                                                  from the current request; `null` uses the
     *                                                                  request path and query string.
     * @param bool                                    $preserveFragment Whether the page object asks the client to keep
     *                                                                  the URL fragment across a redirect.
     * @param ResponseFactory|null                    $factory          The factory whose flash data this page carries
     *                                                                  and flashes into; `null` uses the shared
     *                                                                  `inertia` service.
     */
    public function __construct(
        private readonly string $component,
        private array $props,
        private readonly string $version,
        private string $rootView,
        private readonly bool $encryptHistory = false,
        private readonly bool $clearHistory = false,
        private readonly array $sharedProps = [],
        private readonly ?Closure $urlResolver = null,
        private readonly bool $preserveFragment = false,
        private readonly ?ResponseFactory $factory = null,
    ) {
    }

    /**
     * Flashes data for this page, as `ResponseFactory::flash()` does: the page built by
     * `toResponse()` carries it under `flash`, and no later page does.
     *
     * @param array<string, mixed>|BackedEnum|string|UnitEnum $key
     */
    public function flash(array|BackedEnum|string|UnitEnum $key, mixed $value = null): self
    {
        $this->flashSource()->flash($key, $value);

        return $this;
    }

    /**
     * Adds props to this page: a map is merged over the existing props, a properties provider
     * is expanded when the page is built, and a name with a value sets one prop. A later key
     * wins over a render prop of the same name.
     *
     * @param array<string, mixed>|ProvidesInertiaProperties|string $key
     */
    public function with(array|ProvidesInertiaProperties|string $key, mixed $value = null): self
    {
        if ($key instanceof ProvidesInertiaProperties) {
            $this->props[] = $key;
        } elseif (is_array($key)) {
            $this->props = array_merge($this->props, $key);
        } else {
            $this->props[$key] = $value;
        }

        return $this;
    }

    /**
     * Adds variables for the root view only; the page object is never affected, and a variable
     * named `page` is replaced by the page object.
     *
     * @param array<string, mixed>|string $key
     */
    public function withViewData(array|string $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->viewData = array_merge($this->viewData, $key);
        } else {
            $this->viewData[$key] = $value;
        }

        return $this;
    }

    /**
     * Sets the root view this response renders for a non-Inertia visit.
     */
    public function rootView(string $rootView): self
    {
        $this->rootView = $rootView;

        return $this;
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
     * metadata the props resolver collected (`sharedProps`, `mergeProps`, `prependProps`,
     * `deepMergeProps`, `matchPropsOn`, `deferredProps`, `rescuedProps`, `scrollProps`,
     * `onceProps`) when it is not empty, then `clearHistory`, `encryptHistory`, `flash` and
     * `preserveFragment` when each holds — the shape both `asJson()` and `asHtml()` serialize
     * verbatim.
     *
     * @return array<string, mixed>
     */
    private function buildPageObject(IncomingRequest $request): array
    {
        [$props, $metadata] = (new PropsResolver($request, $this->component))->resolve($this->sharedProps, $this->props);

        $page = [
            'component' => $this->component,
            'props'     => $props,
            'url'       => $this->currentUrl($request),
            'version'   => $this->version,
            ...$metadata,
        ];

        if ($this->clearHistory) {
            $page['clearHistory'] = true;
        }

        if ($this->encryptHistory) {
            $page['encryptHistory'] = true;
        }

        $flash = $this->flashSource()->pullFlashed();

        if ($flash !== []) {
            $page['flash'] = $flash;
        }

        if ($this->preserveFragment) {
            $page['preserveFragment'] = true;
        }

        return $page;
    }

    /**
     * The factory whose flash data this page reads: the one that built it, else the shared
     * `inertia` service.
     */
    private function flashSource(): ResponseFactory
    {
        return $this->factory ?? Services::inertia();
    }

    private function isInertiaRequest(IncomingRequest $request): bool
    {
        return $request->hasHeader(Header::INERTIA);
    }

    /**
     * Builds the page object's `url`, from the URL resolver when one is set; otherwise an absolute path with a guaranteed leading slash,
     * plus the query string when present. Never includes scheme or host — the page
     * object's `url` is relative by contract.
     */
    private function currentUrl(IncomingRequest $request): string
    {
        if ($this->urlResolver !== null) {
            return ($this->urlResolver)($request);
        }

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
     * Renders the root view with the page object in its `page` variable beside the view data; the view prints it
     * as a JSON script tag through `inertia_app()` — the entry point for a first visit or a
     * hard reload.
     *
     * @param array<string, mixed> $page The page object built by `buildPageObject()`.
     */
    private function asHtml(ResponseInterface $response, array $page): ResponseInterface
    {
        helper('inertia');

        $html = view($this->rootView, [...$this->viewData, 'page' => $page]);

        return $response->setBody($html);
    }
}
