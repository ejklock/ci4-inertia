<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Filters;

use CodeIgniter\Cookie\Cookie;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Cookie as CookieConfig;
use Config\Session as SessionConfig;

/**
 * Hands the session's CSRF token to the browser client as a script-readable cookie on
 * every successful response, so the HTTP client can echo it in the CSRF header.
 */
final class XsrfTokenCookieFilter implements FilterInterface
{
    private const COOKIE_NAME = 'XSRF-TOKEN';

    /**
     * @param list<string>|null $arguments Unused.
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        return null;
    }

    /**
     * Writes the current masked session token into the response unless the request failed.
     *
     * The cookie lives as long as the session does: a session that never expires gives a
     * browser-session cookie.
     *
     * The cookie is never trusted on the way back in: the token check compares the header
     * against the session, so a planted cookie value cannot pass it.
     *
     * @param list<string>|null $arguments Unused.
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        if ($response->getStatusCode() >= 400) {
            return null;
        }

        $lifetime = config(SessionConfig::class)->expiration;

        $response->setCookie(new Cookie(self::COOKIE_NAME, (string) service('security')->getHash(), [
            'prefix'   => '',
            'path'     => config(CookieConfig::class)->path,
            'domain'   => '',
            'secure'   => config(CookieConfig::class)->secure,
            'httponly' => false,
            'samesite' => Cookie::SAMESITE_LAX,
            'expires'  => $lifetime > 0 ? time() + $lifetime : 0,
        ]));

        return null;
    }
}
