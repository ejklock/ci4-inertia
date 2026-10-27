<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Ssr;

use RuntimeException;

/**
 * Thrown for a failed server-side render when `Config\Inertia::$ssrThrowOnError` is set.
 */
class SsrException extends RuntimeException
{
    /**
     * The render failure that caused the exception, when it was built from one.
     */
    public ?SsrRenderFailed $event = null;

    public static function fromEvent(SsrRenderFailed $event): self
    {
        $message = sprintf('SSR render failed for component [%s]: %s', $event->component(), $event->error);

        if ($event->sourceLocation !== null && $event->sourceLocation !== '') {
            $message .= sprintf(' at %s', $event->sourceLocation);
        }

        $exception        = new self($message);
        $exception->event = $event;

        return $exception;
    }

    public function component(): ?string
    {
        return $this->event?->component();
    }

    public function type(): ?SsrErrorType
    {
        return $this->event?->type;
    }

    public function hint(): ?string
    {
        return $this->event?->hint;
    }

    public function sourceLocation(): ?string
    {
        return $this->event?->sourceLocation;
    }
}
