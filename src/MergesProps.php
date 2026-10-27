<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * The merge surface of a prop that composes a `MergeProp` to hold how it merges: each call asks
 * the using class for a copy carrying the next merge state, so the prop stays immutable.
 *
 * @internal
 */
trait MergesProps
{
    public function merge(): static
    {
        return $this->withMergeState($this->mergeState(), true);
    }

    public function deepMerge(): static
    {
        return $this->withMergeState($this->mergeState()->deepMerge(), true);
    }

    /**
     * @param list<string>|string $matchOn
     */
    public function matchOn(array|string $matchOn): static
    {
        return $this->withMergeState($this->mergeState()->matchOn($matchOn));
    }

    /**
     * @param array<array-key, string>|bool|string $path
     */
    public function append(array|bool|string $path = true, ?string $matchOn = null): static
    {
        return $this->withMergeState($this->mergeState()->append($path, $matchOn));
    }

    /**
     * @param array<array-key, string>|bool|string $path
     */
    public function prepend(array|bool|string $path = true, ?string $matchOn = null): static
    {
        return $this->withMergeState($this->mergeState()->prepend($path, $matchOn));
    }

    public function shouldMerge(): bool
    {
        return $this->mergesFlag();
    }

    public function shouldDeepMerge(): bool
    {
        return $this->mergeState()->shouldDeepMerge();
    }

    /**
     * @return list<string>
     */
    public function matchesOn(): array
    {
        return $this->mergeState()->matchesOn();
    }

    public function appendsAtRoot(): bool
    {
        return $this->mergeState()->appendsAtRoot();
    }

    public function prependsAtRoot(): bool
    {
        return $this->mergeState()->prependsAtRoot();
    }

    /**
     * @return list<string>
     */
    public function appendsAtPaths(): array
    {
        return $this->mergeState()->appendsAtPaths();
    }

    /**
     * @return list<string>
     */
    public function prependsAtPaths(): array
    {
        return $this->mergeState()->prependsAtPaths();
    }

    /**
     * How the prop merges.
     */
    abstract protected function mergeState(): MergeProp;

    /**
     * Whether merging is on.
     */
    abstract protected function mergesFlag(): bool;

    /**
     * A copy holding the given merge state; `$merges` turns merging on when `true` and leaves it
     * as it was when `null`.
     */
    abstract protected function withMergeState(MergeProp $merging, ?bool $merges = null): static;
}
