<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use Closure;

/**
 * Marks a prop value whose key always survives partial-reload filtering, even when named
 * in `X-Inertia-Partial-Except` or omitted from `X-Inertia-Partial-Data`.
 *
 * `errors` is shared this way precisely so a partial reload that neither requested nor
 * named it still receives it. `InertiaResponse` unions every
 * `AlwaysProp` key into the requested-key set before filtering, then resolves it through
 * the same `ResolvableProp` path as a `LazyProp`.
 */
final readonly class AlwaysProp implements ResolvableProp
{
    private Closure $callback;

    /**
     * @param callable $callback Produces the prop's real value when `resolve()` is
     *                           called. Never invoked at construction time.
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);
    }

    /**
     * Invokes the wrapped callback and returns its result.
     *
     * @return mixed The resolved prop value, in whatever shape the wrapped callback
     *               returns.
     */
    public function resolve(): mixed
    {
        return ($this->callback)();
    }
}
