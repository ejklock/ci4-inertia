<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Ssr;

/**
 * A gateway that can tell whether its SSR server is up, which `inertia:check-ssr` reports.
 */
interface HasHealthCheck
{
    /**
     * Whether the SSR server is healthy and responsive.
     */
    public function isHealthy(): bool;
}
