<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use ArrayAccess;
use BackedEnum;
use Closure;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Ejklock\Ci4Inertia\Support\Header;
use Ejklock\Ci4Inertia\Support\SessionKey;
use InvalidArgumentException;
use UnitEnum;

/**
 * The public entry point a controller invokes to build an Inertia response: `render()`
 * for a page, `defer()` for a prop the client loads afterwards, `merge()` and `deepMerge()` for a
 * prop the client combines with its current value, `optional()` and `always()` for props a partial
 * reload decides on, `share()`, `getShared()` and `flushShared()` for props carried across every
 * render on this instance, `version()` and `getVersion()` to override and read how the asset
 * version is resolved, `setRootView()`, `resolveUrlUsing()` and `transformComponentUsing()` to
 * change the root view, the page URL and the component name of the renders that follow, and
 * `location()` to answer a stale-asset reload, `flash()`, `getFlashed()` and `pullFlashed()` for
 * one-time data the next page carries, `preserveFragment()` to keep the URL fragment across a
 * redirect and `back()` to redirect to the previous location. A prop value is a scalar, an array, `null`, a closure or invokable object called only
 * when the prop is sent, a `JsonSerializable` object, or one of the prop classes; any other
 * object is encoded as JSON by the response.
 */
final class ResponseFactory
{
    /**
     * CodeIgniter's own session key for the last page URL, the one `back()` falls back to.
     */
    private const PREVIOUS_URL_SESSION_KEY = '_ci_previous_url';

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
     * @var (Closure(IncomingRequest): string)|null
     */
    private ?Closure $urlResolver = null;

    private ?Closure $componentTransformer = null;

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
        private string $rootView,
        private Closure $versionResolver,
        private bool $encryptHistory = false,
    ) {
    }

    /**
     * Builds the page response for one component: shared props merged with this
     * render's props, the latter winning on key collision.
     *
     * @param BackedEnum|string|UnitEnum                        $component The component name: a string, a string-backed
     *                                                                     enum read by its value, or a pure enum read by
     *                                                                     its name. The component transformer, when set,
     *                                                                     sees it first.
     * @param array<array-key, mixed>|ProvidesInertiaProperties $props     Per-render props; a closure
     *                                                                     or invokable object is called
     *                                                                     only when the prop is sent. A
     *                                                                     `ProvidesInertiaProperties`
     *                                                                     stands for a list of that one.
     *
     * @throws InvalidArgumentException When the component is not a string once the transformer and
     *                                  the enum mapping have run, such as an int-backed enum.
     */
    public function render(BackedEnum|string|UnitEnum $component, array|ProvidesInertiaProperties $props = []): InertiaResponse
    {
        $component = $this->componentName($component);

        if ($props instanceof ProvidesInertiaProperties) {
            $props = [$props];
        }

        return new InertiaResponse(
            $component,
            $props,
            $this->getVersion(),
            $this->rootView,
            $this->encryptHistory,
            $this->pullFlag(SessionKey::CLEAR_HISTORY),
            $this->sharedProps,
            $this->urlResolver,
            $this->pullFlag(SessionKey::PRESERVE_FRAGMENT),
            $this,
        );
    }

    /**
     * Sets the root view of the renders that follow; a render built earlier keeps the view it
     * was built with.
     */
    public function setRootView(string $rootView): void
    {
        $this->rootView = $rootView;
    }

    /**
     * Sets how the page object's `url` is computed for the renders that follow; `null`
     * restores the request path and query string.
     *
     * @param (Closure(IncomingRequest): string)|null $urlResolver Receives the current request.
     */
    public function resolveUrlUsing(?Closure $urlResolver = null): void
    {
        $this->urlResolver = $urlResolver;
    }

    /**
     * Sets a rewrite applied to the component of every render that follows, before the enum
     * mapping; a `null` result keeps the original component, and `null` here stops transforming.
     */
    public function transformComponentUsing(?Closure $componentTransformer = null): void
    {
        $this->componentTransformer = $componentTransformer;
    }

    /**
     * Marks a prop the first render leaves out and a partial reload sends only when it names
     * the prop: pass the result as a prop value to `render()` or `share()`.
     *
     * @param callable $callback Produces the prop's value; invoked only when the prop is sent.
     */
    public function optional(callable $callback): OptionalProp
    {
        return new OptionalProp($callback);
    }

    /**
     * Marks a prop every response carries, partial reloads that name other props included:
     * pass the result as a prop value to `render()` or `share()`.
     *
     * @param mixed $value The value sent as-is, or a closure or invokable object called only
     *                     when the prop is sent.
     */
    public function always(mixed $value): AlwaysProp
    {
        return new AlwaysProp($value);
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
        $this->setFlag(SessionKey::CLEAR_HISTORY);
    }

    /**
     * Asks the client to keep the URL fragment once: the next render, in this request or after
     * a redirect, carries `preserveFragment` and the request that sent it consumes the flag.
     */
    public function preserveFragment(): void
    {
        $this->setFlag(SessionKey::PRESERVE_FRAGMENT);
    }

    /**
     * Stores data the next page carries under `flash` and the client does not keep in its history
     * state, such as a toast: it survives a redirect and is dropped once a page has carried it.
     * New data is merged over what is already flashed, so a repeated key takes the latest value.
     *
     * @param array<string, mixed>|BackedEnum|string|UnitEnum $key   A flash key, a string-backed enum read
     *                                                               by its value, a pure enum read by its
     *                                                               name, or a map of keys to values.
     * @param mixed                                           $value The value when `$key` is a single key;
     *                                                               ignored for a map.
     */
    public function flash(array|BackedEnum|string|UnitEnum $key, mixed $value = null): ResponseFactory
    {
        if (! is_array($key)) {
            $key = [match (true) {
                $key instanceof BackedEnum => $key->value,
                $key instanceof UnitEnum   => $key->name,
                default                    => $key,
            } => $value];
        }

        session()->setFlashdata(SessionKey::FLASH_DATA, [...$this->getFlashed(), ...$key]);

        return $this;
    }

    /**
     * Reads the flash data without removing it; empty when the request carries no session, which
     * is then not started.
     *
     * @return array<string, mixed>
     */
    public function getFlashed(): array
    {
        if (! $this->hasSession() || ! session()->has(SessionKey::FLASH_DATA)) {
            return [];
        }

        $flashed = session()->getFlashdata(SessionKey::FLASH_DATA);

        return is_array($flashed) ? $flashed : [];
    }

    /**
     * Reads the flash data and removes it, so no later page carries it; empty when the request
     * carries no session, which is then not started.
     *
     * @return array<string, mixed>
     */
    public function pullFlashed(): array
    {
        $flashed = $this->getFlashed();

        if ($flashed !== []) {
            $session = session();
            $session->remove(SessionKey::FLASH_DATA);
            $session->unmarkFlashdata(SessionKey::FLASH_DATA);
        }

        return $flashed;
    }

    /**
     * Answers a redirect to the previous location: the `Referer` header, else the URL CodeIgniter
     * stored for the last page when the request carries a session, else `$fallback`, else the site
     * root. The session is not started to look for the stored URL.
     *
     * @param array<string, string> $headers  Headers set on the redirect.
     * @param false|string          $fallback Where to go when no previous location is known; `false`
     *                                        stands for the site root.
     */
    public function back(int $status = 302, array $headers = [], false|string $fallback = false): RedirectResponse
    {
        $url = $this->request->getHeaderLine('Referer');

        if ($url === '' && $this->hasSession()) {
            $stored = session()->get(self::PREVIOUS_URL_SESSION_KEY);
            $url    = is_string($stored) ? $stored : '';
        }

        if ($url === '') {
            $url = $fallback === false ? '/' : $fallback;
        }

        $response = redirect()->to($url, $status);

        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response;
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
     * Reads the shared props.
     *
     * @param string|null $key     An exact shared key, or a dot path into nested arrays; an empty
     *                             or missing key reads every shared prop.
     * @param mixed       $default Returned when the key is not shared; a shared `null` is a value
     *                             and wins over it. A closure is called and its result returned.
     */
    public function getShared(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '' || $key === '0') {
            return $this->sharedProps;
        }

        return $this->sharedByPath($key, $default);
    }

    /**
     * Drops every shared prop, so the renders that follow carry none.
     */
    public function flushShared(): void
    {
        $this->sharedProps = [];
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
     * Reads the asset version, invoking the version resolver at most once per instance until
     * `version()` replaces it.
     */
    public function getVersion(): string
    {
        return $this->resolvedVersion ??= ($this->versionResolver)();
    }

    /**
     * Answers a stale-asset reload: a `409` carrying the fresh `X-Inertia-Location` when
     * the current request is an Inertia visit, or a plain redirect to `$url` otherwise.
     * `$url` is taken verbatim from the caller and never derived from request input
     * — the caller is responsible for passing a server-computed value.
     *
     * @param RedirectResponse|string $url A redirect stands for its target: an Inertia visit gets
     *                                     the target in the `409`, any other request gets that
     *                                     redirect itself.
     */
    public function location(RedirectResponse|string $url): ResponseInterface
    {
        if ($this->request->hasHeader(Header::INERTIA)) {
            return response()
                ->setStatusCode(409)
                ->setHeader(Header::LOCATION, $url instanceof RedirectResponse ? $url->getHeaderLine('Location') : $url);
        }

        return $url instanceof RedirectResponse ? $url : redirect()->to($url);
    }

    /**
     * Records in the session that a one-time flag is pending for the next render.
     */
    private function setFlag(string $key): void
    {
        session()->set($key, true);
    }

    /**
     * Reads and removes a pending one-time flag.
     */
    private function pullFlag(string $key): bool
    {
        if (! $this->hasSession()) {
            return false;
        }

        $session = session();

        if (! $session->has($key)) {
            return false;
        }

        $session->remove($key);

        return true;
    }

    /**
     * Whether the session may be read: it is already started (a write starts it), or the request
     * carries the session cookie. A first visit with neither never starts a session just to
     * look for state in it.
     */
    private function hasSession(): bool
    {
        return isset($_SESSION)
            || $this->request->getCookie(config('Session')->cookieName) !== null;
    }

    /**
     * Applies the component transformer, then maps an enum to its value or name.
     *
     * @throws InvalidArgumentException When the result is not a string.
     */
    private function componentName(BackedEnum|string|UnitEnum $component): string
    {
        if ($this->componentTransformer !== null) {
            $component = ($this->componentTransformer)($component) ?? $component;
        }

        $name = match (true) {
            $component instanceof BackedEnum => $component->value,
            $component instanceof UnitEnum   => $component->name,
            default                          => $component,
        };

        if (! is_string($name)) {
            throw new InvalidArgumentException('Component argument must be of type string or a string BackedEnum');
        }

        return $name;
    }

    /**
     * Reads a shared prop by an exact key first, then by a dot path through nested arrays, the
     * way Laravel's `Arr::get` does: nested `ArrayAccess` objects are walked like arrays, and a
     * closure default is called, only when nothing is found, and its result returned.
     */
    private function sharedByPath(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->sharedProps)) {
            return $this->sharedProps[$key];
        }

        $current = $this->sharedProps;

        foreach (explode('.', $key) as $segment) {
            $found = match (true) {
                is_array($current)              => array_key_exists($segment, $current),
                $current instanceof ArrayAccess => $current->offsetExists($segment),
                default                         => false,
            };

            if (! $found) {
                return $default instanceof Closure ? $default() : $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
