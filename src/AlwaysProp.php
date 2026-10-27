<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * Marks a prop value that is sent on every response, even when a partial reload names other
 * props or lists it in `X-Inertia-Partial-Except`.
 *
 * `errors` is shared this way precisely so a partial reload that neither requested nor
 * named it still receives it.
 */
final readonly class AlwaysProp
{
    /**
     * @param mixed $source What the prop sends; a callable object is called only when the prop
     *                      is resolved, anything else is sent as it is.
     */
    public function __construct(private mixed $source)
    {
    }

    /**
     * Calls the source when it is a callable object, otherwise returns it untouched.
     */
    public function __invoke(): mixed
    {
        $source = $this->source;

        if (is_object($source) && is_callable($source)) {
            return $source();
        }

        return $source;
    }
}
