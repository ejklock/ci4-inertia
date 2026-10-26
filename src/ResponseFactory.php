<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use Closure;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * The public entry point a controller invokes to build an Inertia response: `render()`
 * for a page, `share()` for props carried across every render on this instance, `version()`
 * to override how the asset version is resolved, and `location()` to answer a stale-asset
 * reload. The props array is treated as an egress channel — only a scalar,
 * array, `null`, or `ResolvableProp` value may cross it, so a controller must state what
 * leaves the server rather than hand over an entity by accident.
 */
final class ResponseFactory
{
    /**
     * @var array<string, mixed>
     */
    private array $sharedProps = [];

    /**
     * Memoized asset version; `null` until `resolveVersion()` is first invoked, cleared
     * whenever `version()` replaces the resolver.
     */
    private ?string $resolvedVersion = null;

    /**
     * @param IncomingRequest   $request         The current request; read only by
     *                                           `location()` to tell an Inertia reload
     *                                           apart from a first visit.
     * @param string            $rootView        The root view forwarded to every
     *                                           `InertiaResponse` this instance builds.
     * @param Closure(): string $versionResolver Produces the asset version. Invoked at
     *                                           most once per instance and memoized —
     *                                           `version()` replaces it and clears the
     *                                           memo for the next `render()`.
     */
    public function __construct(
        private readonly IncomingRequest $request,
        private readonly string $rootView,
        private Closure $versionResolver,
    ) {
    }

    /**
     * Builds the page response for one component: shared props merged with this
     * render's props, the latter winning on key collision.
     *
     * @param array<string, mixed> $props Per-render props, each a scalar, array, `null`,
     *                                    or `ResolvableProp`.
     *
     * @throws InvalidArgumentException When a prop value is none of those shapes; the
     *                                  message names the offending key.
     */
    public function render(string $component, array $props = []): InertiaResponse
    {
        $this->assertValidPropValues($props);

        $merged = [...$this->sharedProps, ...$props];

        return new InertiaResponse($component, $merged, $this->resolveVersion(), $this->rootView);
    }

    /**
     * Adds one or more props carried on every subsequent `render()` call on this
     * instance — the vehicle for cross-cutting data such as validation errors or the
     * authenticated user's display chrome.
     *
     * @param array<string, mixed>|string $key   A single prop name, or a map of prop
     *                                           names to values to share at once.
     * @param mixed                       $value The value to share when `$key` is a
     *                                           single prop name; ignored otherwise.
     *
     * @throws InvalidArgumentException When a shared value is not scalar, array, `null`,
     *                                  or `ResolvableProp`; the message names the
     *                                  offending key.
     */
    public function share(array|string $key, mixed $value = null): void
    {
        $additions = is_array($key) ? $key : [$key => $value];

        $this->assertValidPropValues($additions);

        $this->sharedProps = [...$this->sharedProps, ...$additions];
    }

    /**
     * Sets the asset version used by every subsequent `render()`: a literal string, or a
     * resolver invoked at most once and memoized for the rest of this instance's
     * lifetime, however many renders follow.
     */
    public function version(callable|string $version): void
    {
        $this->versionResolver = is_string($version)
            ? static fn (): string => $version
            : Closure::fromCallable($version);

        $this->resolvedVersion = null;
    }

    /**
     * Answers a stale-asset reload: a `409` carrying the fresh `X-Inertia-Location` when
     * the current request is an Inertia visit, or a plain redirect to `$url` otherwise.
     * `$url` is taken verbatim from the caller and never derived from request input
     * — the caller is responsible for passing a server-computed value.
     */
    public function location(string $url): ResponseInterface
    {
        if ($this->request->hasHeader('X-Inertia')) {
            return response()
                ->setStatusCode(409)
                ->setHeader('X-Inertia-Location', $url);
        }

        return redirect()->to($url);
    }

    /**
     * Asserts every value in `$props` is a scalar, array, `null`, or `ResolvableProp`;
     * throws naming the first offending key.
     *
     * @param array<string, mixed> $props The prop map to validate, keyed by prop name.
     *
     * @throws InvalidArgumentException When a value is none of the allowed shapes; the
     *                                  message names the offending key.
     */
    private function assertValidPropValues(array $props): void
    {
        foreach ($props as $key => $value) {
            if (! $this->isValidPropValue($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Inertia prop "%s" must be a scalar, array, null, or ResolvableProp, %s given.',
                    $key,
                    get_debug_type($value),
                ));
            }
        }
    }

    /**
     * Reports whether `$value` is one of the shapes allowed to cross the props egress
     * channel.
     *
     * @param mixed $value The candidate prop value to check.
     *
     * @return bool `true` when `$value` is a scalar, array, `null`, or `ResolvableProp`;
     *              `false` otherwise.
     */
    private function isValidPropValue(mixed $value): bool
    {
        return $value === null
            || is_scalar($value)
            || is_array($value)
            || $value instanceof ResolvableProp;
    }

    /**
     * Resolves the asset version, invoking the version resolver at most once per
     * instance and reusing the memoized result for every render that follows, until
     * `version()` replaces the resolver and clears the memo.
     *
     * @return string The resolved asset version.
     */
    private function resolveVersion(): string
    {
        return $this->resolvedVersion ??= ($this->versionResolver)();
    }
}
