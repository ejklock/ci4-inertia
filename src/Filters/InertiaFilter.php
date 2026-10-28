<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Filters;

use Closure;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Method;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Ejklock\Ci4Inertia\AlwaysProp;
use Ejklock\Ci4Inertia\ResponseFactory;
use Ejklock\Ci4Inertia\Ssr\ExcludesSsrPaths;
use Ejklock\Ci4Inertia\Support\Header;
use Ejklock\Ci4Inertia\Support\SessionKey;
use Ejklock\Ci4Inertia\Support\SiteOrigin;

/**
 * Enforces the wire-level Inertia protocol rules at the filter layer: shares the CI4
 * session validation errors as the `errors` prop before every render, answers a stale
 * asset version with a `409` reload ahead of the controller, and marks every response
 * `Vary: X-Inertia`. After the controller, an Inertia request also has its `PUT`/`PATCH`/`DELETE`
 * `302` converted to `303`, an empty `200` redirected back, a redirect to a URL with a
 * `#fragment` turned into a `409` with `X-Inertia-Redirect`, and an Inertia `GET` recorded as
 * the previous URL (config `storePreviousUrl`). An application extends this class and overrides `share()` to add its own
 * shared props, and `version()` to compute the asset version at runtime; the stale-version
 * check and every page object use that one value, resolved at most once per request and
 * only when the check or a render needs it, so a later `service('inertia')->version()`
 * call is not compared. In CodeIgniter 4.7's default filter order the `before` filters in
 * `$globals` run ahead of `$methods`, `$filters` and route filters, so an authentication
 * or authorization filter that must run first belongs in `$globals['before']` ahead of
 * this filter; a denial from it short-circuits the chain before this filter's `before()`
 * runs, and no branch here re-checks authorization.
 */
class InertiaFilter implements FilterInterface
{
    private const PREVIOUS_URL_SESSION_KEY = '_ci_previous_url';

    /**
     * Whether the `errors` prop carries every message of a field as a list, rather than
     * the first one. An application sets it by extending this filter.
     */
    protected bool $withAllErrors = false;

    /**
     * Paths excluded from server-side rendering on every request this filter handles, as
     * patterns where `*` matches any characters. An application sets it by extending this filter.
     *
     * @var list<string>
     */
    protected array $withoutSsr = [];

    /**
     * Installs `version()` as the lazy asset version resolver, shares every entry of
     * `share()` as a prop, then answers a stale-version `GET` with the `409` reload
     * response before the controller runs.
     *
     * @param list<string>|null $arguments Unused; this filter takes no route arguments.
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        assert($request instanceof IncomingRequest);

        $inertia = service('inertia');

        $resolveVersion = $this->installVersionResolver($request, $inertia);
        $this->shareProps($request, $inertia);
        $this->excludeSsrPaths();

        if (! $this->isVersionCheckEligible($request)) {
            return null;
        }

        $currentVersion = $resolveVersion();

        if ($request->getHeaderLine(Header::VERSION) === $currentVersion) {
            return null;
        }

        return $this->staleVersionResponse($request, $inertia, $currentVersion);
    }

    /**
     * Installs this filter's version on `$inertia` for a page the exception handler renders,
     * where no `before` filter has run, and shares every entry of `share()` when
     * `$withSharedData` is set. It is the same setup `before()` performs, minus the stale-version
     * check, so a page rendered for an uncaught exception carries what a normal render does.
     *
     * @internal Called by `ExceptionResponse`; an application does not call it.
     */
    public function prepareExceptionRender(IncomingRequest $request, ResponseFactory $inertia, bool $withSharedData): void
    {
        $this->installVersionResolver($request, $inertia);

        if ($withSharedData) {
            $this->shareProps($request, $inertia);
        }
    }

    /**
     * Installs `version()` on `$inertia` as its lazy version resolver, memoized so it runs at
     * most once however often the version is read, and returns that resolver.
     *
     * @return Closure(): string
     */
    private function installVersionResolver(IncomingRequest $request, ResponseFactory $inertia): Closure
    {
        $memo           = null;
        $resolveVersion = function () use ($request, &$memo): string {
            return $memo ??= $this->version($request);
        };
        $inertia->version($resolveVersion);

        return $resolveVersion;
    }

    /**
     * Shares every entry of `share()` on `$inertia` as a prop.
     */
    private function shareProps(IncomingRequest $request, ResponseFactory $inertia): void
    {
        foreach ($this->share($request) as $key => $prop) {
            $inertia->share($key, $prop);
        }
    }

    /**
     * Excludes `$withoutSsr` from server-side rendering when it lists paths and the SSR gateway
     * can exclude paths; any other gateway is left as it is.
     */
    private function excludeSsrPaths(): void
    {
        if ($this->withoutSsr === []) {
            return;
        }

        $gateway = service('inertiaSsrGateway');

        if ($gateway instanceof ExcludesSsrPaths) {
            $gateway->except($this->withoutSsr);
        }
    }

    /**
     * The asset version of this request, used for the stale-version check and carried by
     * every page object. Defaults to the `Inertia` config's `version`; an extending class
     * overrides it to compute the version from the request. It runs at most once per
     * request, and only when the stale check of an Inertia `GET` or a render needs the
     * version; the check runs before the controller, so a `version()` call made later in a
     * controller is not seen by it.
     */
    protected function version(IncomingRequest $request): string
    {
        return (config('Inertia')->version)();
    }

    /**
     * The props shared on every Inertia render, keyed by prop name: the current session
     * validation errors as `errors` (the `errors` session array, else the errors
     * `redirect()->withInput()` flashed), an empty object when there are none or the request
     * carries no session, with one message per field unless `$withAllErrors` is on, and
     * scoped under the `X-Inertia-Error-Bag` name when the request sends one. An extending
     * class merges its own entries into the parent's result.
     *
     * @return array<string, mixed>
     */
    protected function share(IncomingRequest $request): array
    {
        return [
            'errors' => new AlwaysProp(fn (): object => $this->resolveValidationErrors($request)),
        ];
    }

    /**
     * Marks every response `Vary: X-Inertia`, and flashes the Inertia flash data again on any
     * redirect so it reaches the page the redirect leads to; on an Inertia request it then applies the
     * protocol rules in inertia-laravel's order: records an Inertia `GET` as the previous
     * URL, answers an empty `200` through `onEmptyResponse()`, converts a `302` to a `303`
     * when the method was `PUT`, `PATCH`, or `DELETE`, and answers a redirect whose
     * `Location` holds a `#fragment` through `onRedirectWithFragment()` unless the request
     * is a prefetch. A request without `X-Inertia` is returned as it is, apart from that flash.
     *
     * @param list<string>|null $arguments Unused; this filter takes no route arguments.
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface
    {
        assert($request instanceof IncomingRequest);

        $wasRedirect = $this->isRedirectStatus($response);

        if ($wasRedirect) {
            $this->reflashInertiaFlashData($request);
        }

        if (! $request->hasHeader(Header::INERTIA)) {
            return self::applyVaryHeader($response);
        }

        if ($this->shouldStoreCurrentUrl($request, $response)) {
            session()->set(self::PREVIOUS_URL_SESSION_KEY, (string) $request->getUri());
        }

        if ($this->isEmptyOk($response)) {
            $response = $this->onEmptyResponse($request, $response);
        }

        if ($this->isDestructiveMethodRedirect($request, $response)) {
            $response->setStatusCode(303);
        }

        if ($wasRedirect && str_contains($response->getHeaderLine('Location'), '#') && ! $this->isPrefetch($request)) {
            $response = $this->onRedirectWithFragment($request, $response);
        }

        return self::applyVaryHeader($response);
    }

    /**
     * Reports whether an Inertia visit is to be recorded as CodeIgniter's previous URL, so
     * `redirect()->back()` returns to it: only a `GET` that `$response` answers with a
     * rendered page (a `200` carrying `X-Inertia`), with `storePreviousUrl` on, a session
     * the request already carries (its cookie, or one already started), and neither a prefetch,
     * a Precognition request, nor a partial reload of the component the response renders. A
     * redirect, an empty `200` or a `409` is never recorded: CodeIgniter reads the stored URL
     * ahead of the `Referer`, so recording one would send `back()` to the page itself.
     */
    public function shouldStoreCurrentUrl(IncomingRequest $request, ResponseInterface $response): bool
    {
        return config('Inertia')->storePreviousUrl
            && $request->getMethod() === Method::GET
            && $response->getStatusCode() === 200
            && $response->getHeaderLine(Header::INERTIA) === 'true'
            && $this->requestCarriesSession($request)
            && ! $this->isPrefetch($request)
            && $request->getHeaderLine(Header::PRECOGNITION) !== 'true'
            && ! $this->isPartialReloadOfRenderedComponent($request, $response);
    }

    /**
     * The response for an Inertia request answered with an empty `200`: a redirect to the
     * previous URL in the order `previous_url()` reads it (the stored one, else the `Referer`,
     * else the site root), skipping a source that is not on the site's own origin so a forged
     * `Referer` cannot send the visitor elsewhere. It does not use `redirect()->back()`, which starts a session the request
     * may not carry. The status is a fixed `302`, so the filter's own rewrite turns it into
     * `303` for `PUT`, `PATCH` and `DELETE` whatever protocol CodeIgniter would pick. An
     * extending class overrides it to answer differently.
     */
    protected function onEmptyResponse(IncomingRequest $request, ResponseInterface $response): ResponseInterface
    {
        $stored = isset($_SESSION) ? session(self::PREVIOUS_URL_SESSION_KEY) : null;
        $url    = (is_string($stored) ? SiteOrigin::urlOrNull($stored) : null)
            ?? SiteOrigin::urlOrNull((string) $request->getServer('HTTP_REFERER', FILTER_SANITIZE_URL))
            ?? site_url('/');

        return redirect()->to($url, 302);
    }

    /**
     * The response for an Inertia redirect to a URL with a `#fragment`, which the browser
     * would drop on a followed redirect: an empty `409` whose `X-Inertia-Redirect` header
     * carries the `Location`, so the client navigates there itself. It reuses `$response`,
     * so the cookies already set on it (session, CSRF) are kept, and drops its `Location`
     * header, which the client must not follow. An extending class overrides it to answer
     * differently.
     */
    protected function onRedirectWithFragment(IncomingRequest $request, ResponseInterface $response): ResponseInterface
    {
        $location = $response->getHeaderLine('Location');

        return $response
            ->removeHeader('Location')
            ->setStatusCode(409)
            ->setBody('')
            ->setHeader(Header::REDIRECT, $location);
    }

    /**
     * Reports whether `$response` is a `200` with an empty body; a `204` or a body of `0`
     * is not.
     */
    private function isEmptyOk(ResponseInterface $response): bool
    {
        return $response->getStatusCode() === 200 && (string) $response->getBody() === '';
    }

    /**
     * Reports whether `$response` carries one of the redirect statuses.
     */
    private function isRedirectStatus(ResponseInterface $response): bool
    {
        return in_array($response->getStatusCode(), [201, 301, 302, 303, 307, 308], true);
    }

    /**
     * Reports whether the browser marked the request a prefetch, through `X-Moz`, `Purpose`
     * or `Sec-Purpose`.
     */
    private function isPrefetch(IncomingRequest $request): bool
    {
        return strcasecmp($request->getHeaderLine('X-Moz'), 'prefetch') === 0
            || strcasecmp($request->getHeaderLine('Purpose'), 'prefetch') === 0
            || strcasecmp($request->getHeaderLine('Sec-Purpose'), 'prefetch') === 0;
    }

    /**
     * Reports whether the request is a partial reload of the component `$response` renders:
     * deferred props, polling and infinite scroll are not navigations the visitor came from.
     */
    private function isPartialReloadOfRenderedComponent(IncomingRequest $request, ResponseInterface $response): bool
    {
        $component = $request->getHeaderLine(Header::PARTIAL_COMPONENT);

        if ($component === '') {
            return false;
        }

        $page = json_decode((string) $response->getBody(), true);

        return is_array($page) && ($page['component'] ?? null) === $component;
    }

    /**
     * Reports whether the request already carries a session: a session cookie, or a session
     * the application started earlier in the request. Reading the session service without
     * one would start a session just to find it empty.
     */
    private function requestCarriesSession(IncomingRequest $request): bool
    {
        return $request->getCookie(config('Session')->cookieName) !== null || isset($_SESSION);
    }

    /**
     * Builds the `errors` prop value: the session validation errors with one message per
     * field (every message as a list when `$withAllErrors` is on), under the
     * `X-Inertia-Error-Bag` name when the request sends one and there are errors to scope.
     * A request carrying no session has no errors, and the session is not started to look.
     */
    private function resolveValidationErrors(IncomingRequest $request): object
    {
        if (! $this->requestCarriesSession($request)) {
            return (object) [];
        }

        $errors = array_map(
            fn (mixed $messages): mixed => $this->withAllErrors
                ? array_values((array) $messages)
                : (is_array($messages) ? ($messages[array_key_first($messages)] ?? '') : $messages),
            $this->sessionValidationErrors(),
        );

        $bag = $request->getHeaderLine(Header::ERROR_BAG);

        return (object) ($bag !== '' && $errors !== [] ? [$bag => (object) $errors] : $errors);
    }

    /**
     * Reads the session validation errors, keyed by field name: the `errors` session array
     * when present, whole and never merged; otherwise the errors `withInput()` flashed under
     * `_ci_validation_errors`; otherwise an empty array.
     *
     * @return array<string, mixed>
     */
    private function sessionValidationErrors(): array
    {
        foreach (['errors', '_ci_validation_errors'] as $key) {
            $errors = session($key);

            if (is_array($errors)) {
                return $errors;
            }
        }

        return [];
    }

    /**
     * Reports whether `$request` is a `GET` carrying `X-Inertia` — the only shape the
     * protocol contract subjects to the asset-version check.
     */
    private function isVersionCheckEligible(IncomingRequest $request): bool
    {
        return $request->hasHeader(Header::INERTIA) && $request->getMethod() === Method::GET;
    }

    /**
     * Builds the `409` reload response for a stale `X-Inertia-Version`: reflashes the
     * session so nothing queued for display is lost to the discarded response, then
     * asks `ResponseFactory::location()` for the protocol-correct body and adds the
     * fresh version and `Vary` header, since `before()`-returned responses skip this
     * filter's own `after()` entirely.
     */
    private function staleVersionResponse(IncomingRequest $request, ResponseFactory $inertia, string $currentVersion): ResponseInterface
    {
        $this->reflashSessionData();

        $response = $inertia->location($this->currentPath($request))
            ->setHeader(Header::VERSION, $currentVersion);

        return self::applyVaryHeader($response);
    }

    /**
     * Re-marks every currently flashed session key as flashdata, extending its life by
     * one more request so a discarded stale-version response cannot consume it.
     */
    private function reflashSessionData(): void
    {
        $session   = session();
        $flashKeys = array_keys($session->getFlashdata());

        if ($flashKeys !== []) {
            $session->keepFlashdata($flashKeys);
        }
    }

    /**
     * Flashes the Inertia flash data again, so a redirect response does not consume it before
     * the page it redirects to can carry it. Nothing is written when there is none, and a request
     * that carries no session is left without one.
     */
    private function reflashInertiaFlashData(IncomingRequest $request): void
    {
        if (! $this->requestCarriesSession($request)) {
            return;
        }

        $session = session();

        if (! $session->has(SessionKey::FLASH_DATA)) {
            return;
        }

        $flashed = $session->getFlashdata(SessionKey::FLASH_DATA);

        if (is_array($flashed) && $flashed !== []) {
            $session->setFlashdata(SessionKey::FLASH_DATA, $flashed);
        }
    }

    /**
     * The request's own path and query string, with a guaranteed leading slash — the
     * server-derived reload target `X-Inertia-Location` must carry,
     * never a client-supplied value such as a `redirect` query parameter.
     */
    private function currentPath(IncomingRequest $request): string
    {
        $path  = '/' . ltrim($request->getPath(), '/');
        $query = $request->getUri()->getQuery();

        return $query === '' ? $path : $path . '?' . $query;
    }

    /**
     * Reports whether `$response` is the `302` this filter must rewrite: the request
     * method was `PUT`, `PATCH`, or `DELETE` — never `POST`, `GET`, or `HEAD`.
     */
    private function isDestructiveMethodRedirect(RequestInterface $request, ResponseInterface $response): bool
    {
        return $response->getStatusCode() === 302
            && in_array($request->getMethod(), [Method::PUT, Method::PATCH, Method::DELETE], true);
    }

    /**
     * Appends `Vary: X-Inertia` to `$response` unless it already names it, and returns it,
     * so callers can use the result inline.
     *
     * @internal Shared with `ExceptionResponse`, whose page no `after` filter reaches.
     */
    public static function applyVaryHeader(ResponseInterface $response): ResponseInterface
    {
        if (! str_contains($response->getHeaderLine('Vary'), Header::INERTIA)) {
            $response->appendHeader('Vary', Header::INERTIA);
        }

        return $response;
    }
}
