<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use InvalidArgumentException;

/**
 * Thrown by `ResponseFactory::render()` when `Config\Inertia::$ensurePagesExist` is on and no
 * page file matches the component name.
 */
class ComponentNotFoundException extends InvalidArgumentException
{
}
