<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Ejklock\Ci4Inertia\Support\PlainPropValue;
use InvalidArgumentException;

/**
 * Produces the Inertia page object for one render and picks the wire
 * representation: the JSON body an Inertia-aware request receives, or the HTML
 * shell that embeds the same object in `data-page` for a first visit or a hard
 * reload. An instance is immutable and single-use — build one per `render()`
 * call.
 */
final readonly class InertiaResponse
{
    /**
     * @param string               $component      The client-side component name Inertia
     *                                             mounts, e.g. `Users/Index`.
     * @param array<string, mixed> $props          The declared props, keyed by name. A value
     *                                             may be a `ResolvableProp` — a `LazyProp` to
     *                                             defer resolution past partial-reload
     *                                             filtering, an `OptionalProp` to also leave
     *                                             it out of a full render, a `DeferProp` to
     *                                             leave it out of a full render and have the
     *                                             client fetch it afterwards, a `MergeProp` to have
     *                                             the client combine it with its current value, or an `AlwaysProp`
     *                                             to bypass that filtering entirely; any other value is
     *                                             sent as-is.
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
     * Assembles the page object: the keys `component`, `props`, `url` and `version`, plus
     * `deferredProps` (a group name → keys object) when this is not a partial reload and at
     * least one `DeferProp` is declared, plus the merge lists (`mergeProps`, `prependProps`, `deepMergeProps`,
     * `matchPropsOn`) that apply, plus `encryptHistory` and `clearHistory` when each holds — the
     * shape both `asJson()` and `asHtml()` serialize verbatim.
     *
     * @return array{component: string, props: array<string, mixed>, url: string, version: string, deferredProps?: object, mergeProps?: list<string>, prependProps?: list<string>, deepMergeProps?: list<string>, matchPropsOn?: list<string>, encryptHistory?: true, clearHistory?: true}
     */
    private function buildPageObject(IncomingRequest $request): array
    {
        $page = [
            'component' => $this->component,
            'props'     => $this->resolveProps($request),
            'url'       => $this->currentUrl($request),
            'version'   => $this->version,
        ];

        $deferred = $this->isPartialReloadForThisComponent($request) ? [] : $this->deferredPropKeysByGroup();
        // An object, so integer-like group names stay a JSON map instead of collapsing into a list.
        $page = $deferred === [] ? $page : [...$page, 'deferredProps' => (object) $deferred];

        $page = array_merge($page, $this->mergeMetadata($request));

        if ($this->encryptHistory) {
            $page['encryptHistory'] = true;
        }

        if ($this->clearHistory) {
            $page['clearHistory'] = true;
        }

        return $page;
    }

    /**
     * Lists the mergeable prop keys by how the client combines them: `mergeProps` (appended),
     * `prependProps`, `deepMergeProps`, and `matchPropsOn` (`"<key>.<field>"`). A list with no
     * entry is left out. A key named in `X-Inertia-Reset` is dropped from every list while its
     * value is still sent, so the client replaces instead of merging; on a partial reload for
     * this component a key is kept only when `X-Inertia-Partial-Data` names it (when not blank)
     * and `X-Inertia-Partial-Except` does not. Only keys are read, so no callback is invoked.
     *
     * @return array{mergeProps?: list<string>, prependProps?: list<string>, deepMergeProps?: list<string>, matchPropsOn?: list<string>}
     */
    private function mergeMetadata(IncomingRequest $request): array
    {
        $append  = [];
        $prepend = [];
        $deep    = [];
        $matches = [];

        foreach ($this->listedMergeStates($request) as $key => $state) {
            $name = (string) $key;

            match ($state->mode) {
                MergeMode::Append  => $append[]  = $name,
                MergeMode::Prepend => $prepend[] = $name,
                MergeMode::Deep    => $deep[]    = $name,
            };

            foreach ($state->matchFields as $field) {
                $matches[] = $name . '.' . $field;
            }
        }

        return array_filter([
            'mergeProps'     => $append,
            'prependProps'   => $prepend,
            'deepMergeProps' => $deep,
            'matchPropsOn'   => $matches,
        ], static fn (array $list): bool => $list !== []);
    }

    /**
     * The merge state of every mergeable prop this response lists, keyed by prop name.
     *
     * @return array<string, MergeState>
     */
    private function listedMergeStates(IncomingRequest $request): array
    {
        $partial = $this->isPartialReloadForThisComponent($request);
        $reset   = $this->splitHeaderList($request->getHeaderLine('X-Inertia-Reset'));
        $only    = $partial ? $this->splitHeaderList($request->getHeaderLine('X-Inertia-Partial-Data')) : [];
        $except  = $partial ? $this->splitHeaderList($request->getHeaderLine('X-Inertia-Partial-Except')) : [];
        $states  = [];

        foreach ($this->props as $key => $value) {
            $name  = (string) $key;
            $state = $value instanceof MergeProp || $value instanceof DeferProp ? $value->mergeState() : null;
            $named = $only === []                || in_array($name, $only, true);

            if ($state instanceof MergeState && $named && ! in_array($name, [...$reset, ...$except], true)) {
                $states[$name] = $state;
            }
        }

        return $states;
    }

    /**
     * Groups the declared `DeferProp` keys by group name, in declaration order — the map the
     * client reads to schedule one follow-up partial reload per group.
     *
     * @return array<string, list<string>>
     */
    private function deferredPropKeysByGroup(): array
    {
        $groups = [];

        foreach ($this->props as $key => $value) {
            if ($value instanceof DeferProp) {
                $groups[$value->group()][] = (string) $key;
            }
        }

        return $groups;
    }

    /**
     * Resolves the props to send: filters to the requested keys first, then resolves any
     * `ResolvableProp` survivor. Reversing that order — resolving before filtering —
     * produces byte-identical output at full cost, silently defeating the laziness callers
     * rely on; the key-first order is a contract, not an accident.
     *
     * @return array<string, mixed>
     */
    private function resolveProps(IncomingRequest $request): array
    {
        $keys     = $this->requestedPropKeys($request);
        $filtered = array_intersect_key($this->props, array_flip($keys));

        $resolved = [];

        foreach ($filtered as $key => $value) {
            $resolved[$key] = $this->resolvePropValue((string) $key, $value);
        }

        return $resolved;
    }

    /**
     * Determines which prop keys belong in this response: every declared prop but the
     * `OptionalProp` and `DeferProp` ones on a full render, or — on a partial reload naming this component —
     * the `X-Inertia-Partial-Data` allow-list (every prop but the optional ones when it is
     * blank) minus `X-Inertia-Partial-Except`, unioned with every `AlwaysProp` key so it
     * survives regardless of either header.
     *
     * @return list<string>
     */
    private function requestedPropKeys(IncomingRequest $request): array
    {
        $withoutOptional = array_values(array_diff(array_keys($this->props), $this->optionalPropKeys()));

        if (! $this->isPartialReloadForThisComponent($request)) {
            return $withoutOptional;
        }

        $only   = $this->splitHeaderList($request->getHeaderLine('X-Inertia-Partial-Data'));
        $except = $this->splitHeaderList($request->getHeaderLine('X-Inertia-Partial-Except'));
        $keys   = $only !== [] ? $only : $withoutOptional;
        $kept   = array_diff($keys, $except);

        return array_values(array_unique([...$kept, ...$this->alwaysPropKeys()]));
    }

    /**
     * Every declared prop key whose value is an `OptionalProp` or a `DeferProp` — the keys left
     * out unless a partial reload for this component names them.
     *
     * @return list<string>
     */
    private function optionalPropKeys(): array
    {
        return array_keys(array_filter(
            $this->props,
            static fn (mixed $value): bool => $value instanceof OptionalProp || $value instanceof DeferProp,
        ));
    }

    /**
     * Every declared prop key whose value is an `AlwaysProp` — the keys that bypass
     * partial-reload filtering entirely, regardless of `X-Inertia-Partial-Data` or
     * `X-Inertia-Partial-Except`.
     *
     * @return list<string>
     */
    private function alwaysPropKeys(): array
    {
        return array_keys(array_filter(
            $this->props,
            static fn (mixed $value): bool => $value instanceof AlwaysProp,
        ));
    }

    private function isPartialReloadForThisComponent(IncomingRequest $request): bool
    {
        return $request->getHeaderLine('X-Inertia-Partial-Component') === $this->component;
    }

    /**
     * Splits a comma-separated header value into trimmed parts; an absent or blank header
     * yields an empty list rather than a list holding one empty string.
     *
     * @return list<string>
     */
    private function splitHeaderList(string $header): array
    {
        if (trim($header) === '') {
            return [];
        }

        return array_map(trim(...), explode(',', $header));
    }

    /**
     * Returns the prop as-is, or its resolved value when it is a `ResolvableProp` — the
     * sole point in this class where a `LazyProp`, `OptionalProp`, `DeferProp` or `AlwaysProp` callback is invoked.
     * The resolved value must be plain data, so a callback cannot hand an entity to the page.
     *
     * @throws InvalidArgumentException When the resolved value is not null, a scalar, an array or a `stdClass`
     *                                  of the same, at any depth.
     */
    private function resolvePropValue(string $key, mixed $value): mixed
    {
        if (! $value instanceof ResolvableProp) {
            return $value;
        }

        $resolved = $value->resolve();

        PlainPropValue::assertResolved($key, $resolved);

        return $resolved;
    }

    private function isInertiaRequest(IncomingRequest $request): bool
    {
        return $request->hasHeader('X-Inertia');
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
     * @param array{component: string, props: array<string, mixed>, url: string, version: string, deferredProps?: object, mergeProps?: list<string>, prependProps?: list<string>, deepMergeProps?: list<string>, matchPropsOn?: list<string>, encryptHistory?: true, clearHistory?: true} $page The page object built by `buildPageObject()`.
     */
    private function asJson(ResponseInterface $response, array $page): ResponseInterface
    {
        return $response
            ->setStatusCode(200)
            ->setHeader('X-Inertia', 'true')
            ->setJSON($page);
    }

    /**
     * Renders the root view shell with the page object embedded, HTML-attribute-escaped,
     * in `data-page` — the entry point for a first visit or a hard reload.
     *
     * @param array{component: string, props: array<string, mixed>, url: string, version: string, deferredProps?: object, mergeProps?: list<string>, prependProps?: list<string>, deepMergeProps?: list<string>, matchPropsOn?: list<string>, encryptHistory?: true, clearHistory?: true} $page The page object built by `buildPageObject()`.
     */
    private function asHtml(ResponseInterface $response, array $page): ResponseInterface
    {
        $html = view($this->rootView, [
            'page' => json_encode($page, JSON_THROW_ON_ERROR),
        ]);

        return $response->setBody($html);
    }
}
