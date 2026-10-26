<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use Closure;

/**
 * Marks a prop value that is sent only when a partial reload names it.
 *
 * Unlike `LazyProp`, which a full render still resolves and sends, an `OptionalProp` is left
 * out of a full render and of any partial reload that does not name it in
 * `X-Inertia-Partial-Data`, and its callback is never invoked there. A page uses it for data
 * that is costly or can fail and that the client asks for when it needs it.
 */
final readonly class OptionalProp implements ResolvableProp
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
     * @return mixed The resolved prop value, in whatever shape the wrapped callback returns.
     */
    public function resolve(): mixed
    {
        return ($this->callback)();
    }
}
