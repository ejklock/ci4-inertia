<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * A prop whose resolution errors may be swallowed instead of failing the request.
 */
interface Rescuable
{
    /**
     * Whether a throwable raised while resolving the prop is logged and the prop left out.
     */
    public function shouldRescue(): bool;
}
