<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * A prop value that turns itself into the value Inertia sends, called only when the prop is
 * sent.
 */
interface ProvidesInertiaProperty
{
    public function toInertiaProperty(PropertyContext $prop): mixed;
}
