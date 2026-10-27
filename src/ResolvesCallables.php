<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * Resolves the callback a prop wraps: a callable object (a closure or an invokable object) is
 * called, while a string or array callable is returned as it is, so a value like 'strtoupper'
 * is never mistaken for a function to run.
 *
 * @internal
 */
trait ResolvesCallables
{
    /**
     * @psalm-suppress InvalidFunctionCall An object accepted by useAsCallable() is callable.
     */
    protected function resolveCallable(mixed $value): mixed
    {
        return $this->useAsCallable($value) ? $value() : $value;
    }

    protected function useAsCallable(mixed $value): bool
    {
        return is_object($value) && is_callable($value);
    }
}
