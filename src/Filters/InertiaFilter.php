<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Method;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Ejklock\Ci4Inertia\AlwaysProp;
use Ejklock\Ci4Inertia\ResponseFactory;

/**
 * Enforces the wire-level Inertia protocol rules at the filter layer: shares the CI4
 * session validation errors as the `errors` prop before every render, answers a stale
 * asset version with a `409` reload ahead of the controller, marks every response
 * `Vary: X-Inertia`, and converts a `302` to `303` after a `PUT`/`PATCH`/`DELETE`
 * redirect. An application extends this class and overrides `share()` to add its own
 * shared props, and `version()` to compute the asset version at runtime; the stale-version
 * check and every page object use that one value, resolved once per request before the
 * controller runs, so a later `service('inertia')->version()` call is not compared. In CodeIgniter 4.7's default filter order the `before` filters in
 * `$globals` run ahead of `$methods`, `$filters` and route filters, so an authentication
 * or authorization filter that must run first belongs in `$globals['before']` ahead of
 * this filter; a denial from it short-circuits the chain before this filter's `before()`
 * runs, and no branch here re-checks authorization.
 */
class InertiaFilter implements FilterInterface
{
    /**
     * Sets the asset version from `version()`, shares every entry of `share()` as a prop,
     * then answers a stale-version `GET` with the `409` reload response before the
     * controller runs.
     *
     * @param list<string>|null $arguments Unused; this filter takes no route arguments.
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        assert($request instanceof IncomingRequest);

        $inertia = service('inertia');

        $currentVersion = $this->version($request);
        $inertia->version($currentVersion);

        foreach ($this->share($request) as $key => $prop) {
            $inertia->share($key, $prop);
        }

        if (! $this->isVersionCheckEligible($request)) {
            return null;
        }

        if ($request->getHeaderLine('X-Inertia-Version') === $currentVersion) {
            return null;
        }

        return $this->staleVersionResponse($request, $inertia, $currentVersion);
    }

    /**
     * The asset version of this request, used for the stale-version check and carried by
     * every page object. Defaults to the `Inertia` config's `version`; an extending class
     * overrides it to compute the version from the request. It runs once per request,
     * before the controller, so a `version()` call made later in a controller is not seen
     * by the check.
     */
    protected function version(IncomingRequest $request): string
    {
        return (config('Inertia')->version)();
    }

    /**
     * The props shared on every Inertia render, keyed by prop name: the current session
     * validation errors as `errors` (the `errors` session array, else the errors
     * `redirect()->withInput()` flashed), an empty object when there are none. An extending
     * class merges its own entries into the parent's result.
     *
     * @return array<string, mixed>
     */
    protected function share(IncomingRequest $request): array
    {
        return [
            'errors' => new AlwaysProp(fn (): object => (object) $this->sessionValidationErrors()),
        ];
    }

    /**
     * Marks every response `Vary: X-Inertia`, then converts a `302` to a `303` when the
     * request method was `PUT`, `PATCH`, or `DELETE` — a `POST`-triggered `302` is left
     * untouched.
     *
     * @param list<string>|null $arguments Unused; this filter takes no route arguments.
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface
    {
        $this->applyVaryHeader($response);

        if ($this->isDestructiveMethodRedirect($request, $response)) {
            $response->setStatusCode(303);
        }

        return $response;
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
        return $request->hasHeader('X-Inertia') && $request->getMethod() === Method::GET;
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
            ->setHeader('X-Inertia-Version', $currentVersion);

        return $this->applyVaryHeader($response);
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
     * Appends `Vary: X-Inertia` to `$response` and returns it, so callers can use the
     * result inline.
     */
    private function applyVaryHeader(ResponseInterface $response): ResponseInterface
    {
        $response->appendHeader('Vary', 'X-Inertia');

        return $response;
    }
}
