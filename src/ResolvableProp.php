<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * A prop value whose real value is produced on demand rather than supplied eagerly.
 *
 * `LazyProp`, `OptionalProp` and `AlwaysProp` all implement this so `InertiaResponse` can resolve
 * any through one uniform path, without needing to know which concrete wrapper it is holding.
 * They differ only in whether their key survives partial-reload filtering — a concern
 * this interface does not carry.
 */
interface ResolvableProp
{
    /**
     * Produces the prop's real value.
     *
     * @return mixed The resolved prop value, in whatever shape the wrapped callback
     *               returns.
     */
    public function resolve(): mixed;
}
