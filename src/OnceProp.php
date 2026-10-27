<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * A prop the client keeps after its first load: the callback runs only when the prop is sent,
 * and later Inertia visits that name the prop in `X-Inertia-Except-Once-Props` do not receive it
 * again. Pass it as a prop value to `render()`, or share it with `shareOnce()`.
 *
 * Unlike the other props, a `OnceProp` is mutable: `once()`, `as()`, `until()` and `fresh()`
 * change the instance and return it, so a prop handed to `share()` can still be configured.
 */
final class OnceProp implements Onceable
{
    use ResolvesCallables;
    use ResolvesOnce;

    private mixed $callback;
    private OnceState $once;

    /**
     * @param callable $callback Produces the value when the prop is invoked, never at construction.
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
        $this->once     = new OnceState(enabled: true);
    }

    public function __invoke(): mixed
    {
        return $this->resolveCallable($this->callback);
    }

    private function withOnce(OnceState $state): static
    {
        $this->once = $state;

        return $this;
    }
}
