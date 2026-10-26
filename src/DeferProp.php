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
 *
 * A deferred prop is not mergeable until `merge()`, `prepend()`, `deepMerge()` or `matchOn()` is
 * called; its key is then also listed in the matching merge list, with the same meaning as for
 * `MergeProp`. Each fluent call returns a new instance.
 */
final readonly class DeferProp implements ResolvableProp
{
    private Closure $callback;

    /**
     * @param callable        $callback   Produces the prop's real value when `resolve()` is
     *                                    called. Never invoked at construction time.
     * @param string          $group      The name the client batches this prop's follow-up request
     *                                    under; props sharing a group load together.
     * @param MergeState|null $mergeState Internal, not part of the public API: set by the fluent
     *                                    methods; `null` while the prop is not mergeable.
     *
     * @throws InvalidArgumentException When the group is empty or whitespace only.
     */
    public function __construct(callable $callback, private string $group = 'default', private ?MergeState $mergeState = null)
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
     * Makes the prop mergeable by appending to the client's existing value.
     */
    public function merge(): self
    {
        return $this->withMergeState($this->currentMergeState()->withMode(MergeMode::Append));
    }

    /**
     * Makes the prop mergeable by prepending to the client's existing value.
     */
    public function prepend(): self
    {
        return $this->withMergeState($this->currentMergeState()->withMode(MergeMode::Prepend));
    }

    /**
     * Makes the prop mergeable by deep-merging into the client's existing value.
     */
    public function deepMerge(): self
    {
        return $this->withMergeState($this->currentMergeState()->withMode(MergeMode::Deep));
    }

    /**
     * Makes the prop mergeable and sets the item fields the client dedupes array items on.
     *
     * @throws InvalidArgumentException When a field is empty or whitespace only.
     */
    public function matchOn(string $field, string ...$fields): self
    {
        return $this->withMergeState($this->currentMergeState()->withMatchFields([$field, ...$fields]));
    }

    /**
     * @internal Read by `InertiaResponse` to build the page object's merge lists; `null` while
     *           the prop is not mergeable.
     */
    public function mergeState(): ?MergeState
    {
        return $this->mergeState;
    }

    private function currentMergeState(): MergeState
    {
        return $this->mergeState ?? new MergeState();
    }

    private function withMergeState(MergeState $mergeState): self
    {
        return new self($this->callback, $this->group, $mergeState);
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
