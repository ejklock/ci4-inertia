<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use Closure;
use InvalidArgumentException;

/**
 * Marks a prop value the client combines with the value it already holds instead of replacing it.
 *
 * The prop is sent like any other, and its key is listed under the page object's `mergeProps`
 * (appended), `prependProps` (prepended) or `deepMergeProps` (deep-merged); fields set with
 * `matchOn()` are listed under `matchPropsOn` so the client dedupes array items on them. A
 * `MergeProp` is immutable: each fluent call returns a new instance, and the last of
 * `merge()`, `prepend()` and `deepMerge()` wins while the match fields are kept.
 */
final readonly class MergeProp implements ResolvableProp
{
    private MergeState $state;

    /**
     * @param array<array-key, mixed>|Closure $value An array is returned as-is on resolve; a
     *                                               `Closure` is invoked on resolve and never
     *                                               before.
     * @param MergeState|null                 $state Internal, not part of the public API: set by
     *                                               the fluent methods; append mode without
     *                                               match fields when `null`.
     */
    public function __construct(private array|Closure $value, ?MergeState $state = null)
    {
        $this->state = $state ?? new MergeState();
    }

    /**
     * Appends the value to the client's existing one; the default mode.
     */
    public function merge(): self
    {
        return new self($this->value, $this->state->withMode(MergeMode::Append));
    }

    /**
     * Prepends the value to the client's existing one.
     */
    public function prepend(): self
    {
        return new self($this->value, $this->state->withMode(MergeMode::Prepend));
    }

    /**
     * Deep-merges the value into the client's existing one.
     */
    public function deepMerge(): self
    {
        return new self($this->value, $this->state->withMode(MergeMode::Deep));
    }

    /**
     * Sets the item fields the client dedupes array items on, replacing any set earlier.
     *
     * @throws InvalidArgumentException When a field is empty or whitespace only.
     */
    public function matchOn(string $field, string ...$fields): self
    {
        return new self($this->value, $this->state->withMatchFields([$field, ...$fields]));
    }

    /**
     * @internal Read by `InertiaResponse` to build the page object's merge lists.
     */
    public function mergeState(): MergeState
    {
        return $this->state;
    }

    /**
     * Returns the array as given, or the result of invoking the closure.
     *
     * @return mixed The prop value to send.
     */
    public function resolve(): mixed
    {
        return $this->value instanceof Closure ? ($this->value)() : $this->value;
    }
}
