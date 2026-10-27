<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Asks the client to encrypt the history state of every page rendered behind it, for the
 * routes that show sensitive data, without turning the `Inertia` config's `encryptHistory`
 * on for the whole application. It acts before the controller, so a render in the
 * controller sees it.
 */
class EncryptHistoryFilter implements FilterInterface
{
    /**
     * Turns history encryption on for the renders of this request.
     *
     * @param list<string>|null $arguments Unused; this filter takes no route arguments.
     */
    public function before(RequestInterface $request, $arguments = null): null
    {
        service('inertia')->encryptHistory();

        return null;
    }

    /**
     * Leaves the response as the controller built it.
     *
     * @param list<string>|null $arguments Unused; this filter takes no route arguments.
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface
    {
        return $response;
    }
}
