<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use BackedEnum;
use DateInterval;
use DateTimeInterface;
use UnitEnum;

/**
 * The `Onceable` behaviour shared by the props that can resolve once. The key, expiry and
 * refresh logic lives in `OnceState`; the using class holds a `$once` state and decides how a
 * changed state is stored: an immutable prop returns a modified copy, a mutable one changes
 * itself and returns itself.
 *
 * @internal
 */
trait ResolvesOnce
{
    /**
     * Marks the prop to be resolved only once; `false` turns the behaviour off.
     *
     * @param string|null                             $as    The key the client tracks the prop under
     *                                                       instead of its path.
     * @param DateInterval|DateTimeInterface|int|null $until When the client's copy expires, as a
     *                                                       number of seconds from now, an interval
     *                                                       or an instant.
     */
    public function once(bool $value = true, ?string $as = null, DateInterval|DateTimeInterface|int|null $until = null): static
    {
        $state = $this->once->withEnabled($value);

        if ($as !== null) {
            $state = $state->withKey($as);
        }

        if ($until !== null) {
            $state = $state->withDelay($until);
        }

        return $this->withOnce($state);
    }

    public function shouldResolveOnce(): bool
    {
        return $this->once->enabled;
    }

    public function shouldBeRefreshed(): bool
    {
        return $this->once->refresh;
    }

    public function getKey(): ?string
    {
        return $this->once->key;
    }

    public function as(BackedEnum|string|UnitEnum $key): static
    {
        return $this->withOnce($this->once->withKey($key));
    }

    /**
     * Marks the prop to be sent even when the client already holds it.
     */
    public function fresh(bool $value = true): static
    {
        return $this->withOnce($this->once->withRefresh($value));
    }

    public function until(DateInterval|DateTimeInterface|int $delay): static
    {
        return $this->withOnce($this->once->withDelay($delay));
    }

    public function expiresAt(): ?int
    {
        return $this->once->expiresAt();
    }

    abstract private function withOnce(OnceState $state): static;
}
