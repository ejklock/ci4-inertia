<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\Method;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Ejklock\Ci4Inertia\Support\Header;

/**
 * Makes a redirect that answers an Inertia `PUT`, `PATCH` or `DELETE` a `GET` on the client
 * by converting its `302` to a `303`. `InertiaFilter` already does this for the routes it
 * guards; this filter is for a route group that needs the rewrite without the rest of that
 * filter. A request without `X-Inertia`, a `POST`, and any status other than `302` are
 * left untouched.
 */
class EnsureGetOnRedirectFilter implements FilterInterface
{
    /**
     * Does nothing; the rewrite happens once the controller has answered.
     *
     * @param list<string>|null $arguments Unused; this filter takes no route arguments.
     */
    public function before(RequestInterface $request, $arguments = null): null
    {
        return null;
    }

    /**
     * Rewrites a `302` to `303` when the request is an Inertia `PUT`, `PATCH` or `DELETE`.
     *
     * @param list<string>|null $arguments Unused; this filter takes no route arguments.
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface
    {
        if (
            $response->getStatusCode() === 302
            && $request->hasHeader(Header::INERTIA)
            && in_array($request->getMethod(), [Method::PUT, Method::PATCH, Method::DELETE], true)
        ) {
            $response->setStatusCode(303);
        }

        return $response;
    }
}
