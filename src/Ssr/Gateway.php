<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Ssr;

/**
 * Sends a page object to a server-side rendering engine.
 */
interface Gateway
{
    /**
     * Renders the page and returns its head and body, or null when it was not rendered on the
     * server and the client must render it.
     *
     * @param array<string, mixed> $page
     */
    public function dispatch(array $page): ?Response;
}
