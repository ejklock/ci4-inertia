<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use BackedEnum;
use DateInterval;
use DateTimeInterface;
use UnitEnum;

/**
 * A prop the client keeps after its first load: later Inertia visits name it in
 * `X-Inertia-Except-Once-Props` and the server leaves it out, while the page keeps listing it
 * under `onceProps` so the client knows to hold on to it.
 */
interface Onceable
{
    /**
     * Marks the prop to be resolved only once; `false` turns the behaviour off.
     */
    public function once(bool $value = true): static;

    /**
     * Whether the prop is resolved only once.
     */
    public function shouldResolveOnce(): bool;

    /**
     * Whether the prop is sent even when the client already holds it.
     */
    public function shouldBeRefreshed(): bool;

    /**
     * The custom key the client tracks the prop under, or `null` for the prop's path.
     */
    public function getKey(): ?string;

    /**
     * Sets the key the client tracks the prop under: an enum stands for its value, or its name
     * when it is not backed.
     */
    public function as(BackedEnum|string|UnitEnum $key): static;

    /**
     * Sets the expiry of the client's copy, counted from now.
     */
    public function until(DateInterval|DateTimeInterface|int $delay): static;

    /**
     * The expiry as an absolute time in milliseconds since the epoch, or `null` without one.
     */
    public function expiresAt(): ?int;
}
