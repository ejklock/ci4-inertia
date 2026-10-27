<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Ssr;

use Closure;

interface ConfiguresSsrRequests
{
    /**
     * Configures the HTTP request sent to the SSR server; null clears the configuration.
     */
    public function configureRequestUsing(?Closure $callback = null): void;
}
