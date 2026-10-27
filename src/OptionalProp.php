<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * Marks a prop value that is sent only when a partial reload reaches it.
 *
 * An `OptionalProp` is left out of a full render, and its callback is never invoked there. A
 * page uses it for data that is costly or can fail and that the client asks for when it
 * needs it.
 */
final readonly class OptionalProp implements IgnoreFirstLoad
{
    private mixed $callback;

    /**
     * @param callable $callback Produces the prop's real value when the prop is invoked. Only a
     *                           callable object (a closure or an invokable object) is called; a
     *                           string or array callable is returned as it is, as Laravel does.
     *                           Never called at construction time.
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * Calls the wrapped callback when it is a callable object and returns its result.
     *
     * @psalm-suppress InvalidFunctionCall The constructor only accepts callables, so an object here is one.
     */
    public function __invoke(): mixed
    {
        return is_object($this->callback) ? ($this->callback)() : $this->callback;
    }
}
