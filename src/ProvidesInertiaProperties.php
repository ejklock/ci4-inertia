<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * An object that supplies several keyed props at once when listed at a numeric key of the
 * props (or shared props) array, or given as the whole props argument of a render.
 */
interface ProvidesInertiaProperties
{
    /**
     * @return iterable<string, mixed>
     */
    public function toInertiaProperties(RenderContext $context): iterable;
}
