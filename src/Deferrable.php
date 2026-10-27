<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * A prop the client can load in a follow-up request, batched with the props of its group.
 */
interface Deferrable
{
    /**
     * Whether the prop is left out of the first load and listed under `deferredProps`.
     */
    public function shouldDefer(): bool;

    /**
     * The group the client batches the follow-up request under.
     */
    public function group(): string;
}
