<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Ssr;

interface ExcludesSsrPaths
{
    /**
     * Excludes the given paths from server-side rendering.
     *
     * @param list<string>|string $paths
     */
    public function except(array|string $paths): void;
}
