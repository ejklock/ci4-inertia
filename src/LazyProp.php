<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use Closure;

/**
 * Marks a prop value as resolved on demand rather than eagerly.
 *
 * A controller can hand `render()` a prop whose value is itself callable — CI4 service
 * locators and factories often are — so a bare callable is not enough to say "resolve
 * this lazily". Wrapping it in `LazyProp` is what tells `InertiaResponse` to treat it as
 * deferred rather than as a value to return as-is. A `LazyProp` excluded by partial-reload
 * filtering is never invoked: `InertiaResponse` filters prop keys before resolving any
 * survivor, so an expensive prop costs nothing on a reload that did not request it. Unlike
 * `AlwaysProp`, a `LazyProp`'s key is subject to that filtering like any other prop.
 */
final readonly class LazyProp implements ResolvableProp
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
     * Invokes the wrapped callback and returns its result. Calling this is what makes the
     * prop's value concrete; a `LazyProp` that is filtered out of a response is never
     * asked to `resolve()` at all.
     *
     * @return mixed The resolved prop value, in whatever shape the wrapped callback
     *               returns.
     */
    public function resolve(): mixed
    {
        return ($this->callback)();
    }
}
