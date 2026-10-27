<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Support;

/**
 * The names of the session keys the adapter keeps Inertia state under between a request and
 * the render that consumes it. It holds only constants and cannot be instantiated.
 */
final class SessionKey
{
    /**
     * Pending history clear, consumed by the next render.
     */
    public const CLEAR_HISTORY = 'inertia.clear_history';

    /**
     * Flash data the next page carries under `flash`.
     */
    public const FLASH_DATA = 'inertia.flash_data';

    /**
     * Pending request to keep the URL fragment across a redirect, consumed by the next render.
     */
    public const PRESERVE_FRAGMENT = 'inertia.preserve_fragment';

    private function __construct()
    {
    }
}
