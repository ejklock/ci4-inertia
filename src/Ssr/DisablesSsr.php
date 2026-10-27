<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Ssr;

use Closure;

interface DisablesSsr
{
    /**
     * Sets the condition that decides whether SSR is disabled: a boolean, or a closure called
     * on each dispatch. A set condition replaces the `ssrEnabled` config flag.
     */
    public function disable(bool|Closure $condition): void;
}
