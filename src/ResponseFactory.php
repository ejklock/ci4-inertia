<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use Closure;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Ejklock\Ci4Inertia\Support\Header;
use InvalidArgumentException;

/**
 * The public entry point a controller invokes to build an Inertia response: `render()`
 * for a page, `defer()` for a prop the client loads afterwards, `merge()` and `deepMerge()` for a
 * prop the client combines with its current value, `share()` for props carried across every render on this instance, `version()`
 * to override how the asset version is resolved, and `location()` to answer a stale-asset
 * reload. A prop value is a scalar, an array, `null`, a closure or invokable object called only
 * when the prop is sent, a `JsonSerializable` object, or one of the prop classes; any other
 * object is encoded as JSON by the response.
 */
final class ResponseFactory
{
    /**
     * Session key holding a pending history clear until the next render pulls it.
     */
    private const CLEAR_HISTORY_SESSION_KEY = 'inertia.clear_history';

    /**
     * Whether this request already wrote the session, so a render may read it back even
     * though the request carries no session cookie yet.
     */
    private bool $sessionTouched = false;

    /**
     * @var array<array-key, mixed>
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
     * @param bool              $encryptHistory  Whether pages ask the client to encrypt
     *                                           their history state until `encryptHistory()`
     *                                           overrides it.
     */
    public function __construct(
        private readonly IncomingRequest $request,
        private readonly string $rootView,
        private Closure $versionResolver,
        private bool $encryptHistory = false,
    ) {
    }

    /**
     * Builds the page response for one component: shared props merged with this
     * render's props, the latter winning on key collision.
     *
     * @param array<array-key, mixed>|ProvidesInertiaProperties $props Per-render props; a closure
     *                                                                 or invokable object is called
     *                                                                 only when the prop is sent. A
     *                                                                 `ProvidesInertiaProperties`
     *                                                                 stands for a list of that one.
     */
    public function render(string $component, array|ProvidesInertiaProperties $props = []): InertiaResponse
    {
        if ($props instanceof ProvidesInertiaProperties) {
            $props = [$props];
        }

        return new InertiaResponse(
            $component,
            $props,
            $this->resolveVersion(),
            $this->rootView,
            $this->encryptHistory,
            $this->pullClearHistory(),
            $this->sharedProps,
        );
    }

    /**
     * Turns history encryption on or off for the renders that follow, overriding the
     * configured default; a render built earlier keeps what it was built with.
     */
    public function encryptHistory(bool $encrypt = true): void
    {
        $this->encryptHistory = $encrypt;
    }

    /**
     * Asks the client to discard its encrypted history once: the next render, in this request
     * or after a redirect, carries `clearHistory` and the request that sent it consumes the
     * flag. The flag lives in the session, not in flash data, because flash data stays readable
     * in the request that set it and would fire a second time on the next one.
     */
    public function clearHistory(): void
    {
        session()->set(self::CLEAR_HISTORY_SESSION_KEY, true);

        $this->sessionTouched = true;
    }

    /**
     * Marks a prop the first render leaves out and the client fetches in a follow-up request:
     * pass the result as a prop value to `render()` or `share()`. Props in the same group load
     * together in one request.
     *
     * @param callable $callback Produces the prop's value; invoked only when a partial reload
     *                           reaches the prop.
     * @param string   $group    The batch the client loads this prop in.
     *
     * @throws InvalidArgumentException When the group is empty or whitespace only.
     */
    public function defer(callable $callback, string $group = 'default'): DeferProp
    {
        return new DeferProp($callback, $group);
    }

    /**
     * Marks a prop the client appends to the value it already holds instead of replacing it:
     * pass the result as a prop value to `render()` or `share()`. Chain `prepend()`,
     * `deepMerge()` or `matchOn()` on it to change how the client combines the value.
     *
     * @param mixed $value The value sent as-is, or a closure or invokable object called only
     *                     when the prop is sent.
     */
    public function merge(mixed $value): MergeProp
    {
        return new MergeProp($value);
    }

    /**
     * Marks a prop the client deep-merges into the value it already holds instead of
     * replacing it: pass the result as a prop value to `render()` or `share()`.
     *
     * @param mixed $value The value sent as-is, or a closure or invokable object called only
     *                     when the prop is sent.
     */
    public function deepMerge(mixed $value): MergeProp
    {
        return (new MergeProp($value))->deepMerge();
    }

    /**
     * Adds one or more props carried on every subsequent `render()` call on this
     * instance — the vehicle for cross-cutting data such as validation errors or the
     * authenticated user's display chrome.
     *
     * @param array<array-key, mixed>|ProvidesInertiaProperties|string $key   A single prop name,
     *                                                                        where a dot path nests
     *                                                                        the value; a map of prop
     *                                                                        names to values to share
     *                                                                        at once; or a provider of
     *                                                                        several props.
     * @param mixed                                                    $value The value to share
     *                                                                        when `$key` is a single
     *                                                                        prop name; ignored
     *                                                                        otherwise.
     */
    public function share(array|ProvidesInertiaProperties|string $key, mixed $value = null): void
    {
        if (is_array($key)) {
            $this->sharedProps = [...$this->sharedProps, ...$key];
        } elseif ($key instanceof ProvidesInertiaProperties) {
            $this->sharedProps[] = $key;
        } else {
            PropsResolver::setByPath($this->sharedProps, $key, $value);
        }
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
        if ($this->request->hasHeader(Header::INERTIA)) {
            return response()
                ->setStatusCode(409)
                ->setHeader(Header::LOCATION, $url);
        }

        return redirect()->to($url);
    }

    /**
     * Reads and removes the pending history clear. The session is consulted only when this
     * request wrote it or carries the session cookie, so a first visit with no cookie never
     * starts a session just to look for the flag.
     */
    private function pullClearHistory(): bool
    {
        if (! $this->sessionTouched && $this->request->getCookie(config('Session')->cookieName) === null) {
            return false;
        }

        $session = session();

        if (! $session->has(self::CLEAR_HISTORY_SESSION_KEY)) {
            return false;
        }

        $session->remove(self::CLEAR_HISTORY_SESSION_KEY);

        return true;
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
