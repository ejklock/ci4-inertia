<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Ssr;

/**
 * The kind of failure a server-side rendering engine reports.
 */
enum SsrErrorType: string
{
    case BrowserApi          = 'browser-api';
    case ComponentResolution = 'component-resolution';
    case Render              = 'render';
    case Connection          = 'connection';
    case Unknown             = 'unknown';

    /**
     * Maps the engine's type string, falling back to `Unknown` for a missing or unrecognised one.
     */
    public static function fromString(?string $value): self
    {
        if ($value === null) {
            return self::Unknown;
        }

        return self::tryFrom($value) ?? self::Unknown;
    }
}
