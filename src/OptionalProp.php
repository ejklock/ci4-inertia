<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use ReflectionClass;

/**
 * Marks a prop value that is sent only when a partial reload reaches it.
 *
 * An `OptionalProp` is left out of a full render, and its callback is never invoked there. A
 * page uses it for data that is costly or can fail and that the client asks for when it
 * needs it. It can also be marked once, so the client keeps its copy after loading it; each once
 * modifier returns a modified copy and leaves the prop as it was.
 */
final readonly class OptionalProp implements IgnoreFirstLoad, Onceable
{
    use ResolvesCallables;
    use ResolvesOnce;

    private mixed $callback;
    private OnceState $once;

    /**
     * @param callable $callback Produces the value when the prop is sent, never at construction.
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
        $this->once     = new OnceState();
    }

    public function __invoke(): mixed
    {
        return $this->resolveCallable($this->callback);
    }

    /**
     * Builds the next immutable instance without the constructor, which takes only the callback.
     *
     * @psalm-suppress InaccessibleProperty The copy is a fresh instance whose readonly properties are still uninitialised.
     */
    private function withOnce(OnceState $state): static
    {
        $copy = (new ReflectionClass($this))->newInstanceWithoutConstructor();

        // This is the one sanctioned copy site; PHPStan only knows constructor writes.
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->callback = $this->callback;
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->once = $state;

        return $copy;
    }
}
