<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use Closure;
use InvalidArgumentException;

/**
 * Marks a prop value the client loads in a follow-up request after the first render.
 *
 * A full render leaves a `DeferProp` out of `props` without invoking its callback and lists its
 * key under the page object's `deferredProps`, grouped by name; the client then issues one
 * partial reload per group naming that group's keys. A partial reload follows the same rule as
 * `OptionalProp`: the prop is resolved and sent only when `X-Inertia-Partial-Data` names it.
 */
final readonly class DeferProp implements ResolvableProp
{
    private Closure $callback;

    /**
     * @param callable $callback Produces the prop's real value when `resolve()` is
     *                           called. Never invoked at construction time.
     * @param string   $group    The name the client batches this prop's follow-up request
     *                           under; props sharing a group load together.
     *
     * @throws InvalidArgumentException When the group is empty or whitespace only.
     */
    public function __construct(callable $callback, private string $group = 'default')
    {
        if (trim($group) === '') {
            throw new InvalidArgumentException('A deferred prop group must not be empty or whitespace only.');
        }

        $this->callback = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);
    }

    /**
     * The group the client batches this prop's follow-up request under.
     */
    public function group(): string
    {
        return $this->group;
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
