<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Filters;

use CodeIgniter\Filters\CSRF;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Security\Exceptions\SecurityException;
use Config\App;

/**
 * Verifies the CSRF token like the stock filter, but answers a failed check on an
 * Inertia visit with a `303` back to the page it was sent from (the on-site `Referer`, else the previous page, else the site root) and an `error` flash, so the page
 * can tell the user to retry. Every other request keeps the `SecurityException` and its
 * `403` error screen, whatever `Config\Security::$redirect` says.
 */
final class InertiaCsrfFilter extends CSRF
{
    /**
     * @param list<string>|null $arguments Unused; this filter takes no route arguments.
     *
     * @return RedirectResponse|null
     *
     * @throws SecurityException When the token check fails on a request that is not an Inertia visit.
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        try {
            service('security')->verify($request);
        } catch (SecurityException $e) {
            if ($request->hasHeader('X-Inertia')) {
                return redirect()->to($this->redirectTarget($request), 303)->with('error', lang('Csrf.expired'));
            }

            throw $e;
        }

        return null;
    }

    /**
     * The page a refused visit returns to: the request's on-site `Referer`, else the on-site
     * previous URL kept in the session, else the site root. An Inertia visit never records a
     * previous URL, so the `Referer` is the page the visit was sent from.
     */
    private function redirectTarget(IncomingRequest $request): string
    {
        $previous = isset($_SESSION) ? session('_ci_previous_url') : null;

        return $this->onSiteReferer($request)
            ?? (is_string($previous) ? $this->onSite($previous) : null)
            ?? site_url('/');
    }

    /**
     * The request's `Referer` when it is on the site's own origin, else null.
     */
    private function onSiteReferer(IncomingRequest $request): ?string
    {
        $referer = $request->getServer('HTTP_REFERER', FILTER_SANITIZE_URL);

        return is_string($referer) ? $this->onSite($referer) : null;
    }

    /**
     * The URL itself when its scheme, host and port are the site's own, as `site_url()`
     * builds them from the base URL and the allowed hostnames, else null.
     */
    private function onSite(string $url): ?string
    {
        try {
            $target = new URI($url);
            $site   = new URI(site_url('/'));
        } catch (HTTPException) {
            return null;
        }

        $allowedHosts = array_map(strtolower(...), [$site->getHost(), ...config(App::class)->allowedHostnames]);
        $sameOrigin   = $target->getScheme() === $site->getScheme()
            && $this->effectivePort($target) === $this->effectivePort($site)
            && in_array(strtolower($target->getHost()), $allowedHosts, true);

        return $sameOrigin ? $url : null;
    }

    /**
     * The explicit port of the URI, or the default one of its scheme.
     */
    private function effectivePort(URI $uri): int
    {
        return $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
    }
}
