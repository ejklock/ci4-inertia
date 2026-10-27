<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * A prop the client combines with the value it already holds instead of replacing it.
 */
interface Mergeable
{
    /**
     * Marks the prop for merging.
     */
    public function merge(): static;

    /**
     * Whether the prop is merged.
     */
    public function shouldMerge(): bool;

    /**
     * Whether the prop is deep merged.
     */
    public function shouldDeepMerge(): bool;

    /**
     * The item fields the client matches on when merging, relative to the prop.
     *
     * @return list<string>
     */
    public function matchesOn(): array;

    /**
     * Whether the whole value is appended to the client's existing one.
     */
    public function appendsAtRoot(): bool;

    /**
     * Whether the whole value is prepended to the client's existing one.
     */
    public function prependsAtRoot(): bool;

    /**
     * The paths inside the value that are appended.
     *
     * @return list<string>
     */
    public function appendsAtPaths(): array;

    /**
     * The paths inside the value that are prepended.
     *
     * @return list<string>
     */
    public function prependsAtPaths(): array;
}
