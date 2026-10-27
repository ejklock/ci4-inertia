<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * Marks a prop the first load of a page leaves out: it is sent only when a partial reload
 * reaches it, and its callback never runs on a full render.
 */
interface IgnoreFirstLoad
{
}
