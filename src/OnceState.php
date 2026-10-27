<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use BackedEnum;
use CodeIgniter\I18n\Time;
use DateInterval;
use DateTimeInterface;
use UnitEnum;

/**
 * The once settings of one prop: whether it resolves once, the key the client tracks it under,
 * whether it is forced to refresh, and how long the client's copy lives. Every change returns a
 * new state, so immutable props can hold it.
 *
 * @internal Shared by the props through `ResolvesOnce`; not a public contract.
 */
final readonly class OnceState
{
    public function __construct(
        public bool $enabled = false,
        public bool $refresh = false,
        public ?int $ttl = null,
        public ?string $key = null,
    ) {
    }

    public function withEnabled(bool $enabled): self
    {
        return new self($enabled, $this->refresh, $this->ttl, $this->key);
    }

    public function withRefresh(bool $refresh): self
    {
        return new self($this->enabled, $refresh, $this->ttl, $this->key);
    }

    public function withKey(BackedEnum|string|UnitEnum $key): self
    {
        $name = match (true) {
            $key instanceof BackedEnum => (string) $key->value,
            $key instanceof UnitEnum   => $key->name,
            default                    => $key,
        };

        return new self($this->enabled, $this->refresh, $this->ttl, $name);
    }

    public function withDelay(DateInterval|DateTimeInterface|int $delay): self
    {
        $now = Time::now()->getTimestamp();

        $seconds = match (true) {
            $delay instanceof DateTimeInterface => max(0, $delay->getTimestamp() - $now),
            $delay instanceof DateInterval      => Time::now()->add($delay)->getTimestamp() - $now,
            default                             => $delay,
        };

        return new self($this->enabled, $this->refresh, $seconds, $this->key);
    }

    public function expiresAt(): ?int
    {
        return $this->ttl === null ? null : (Time::now()->getTimestamp() + $this->ttl) * 1000;
    }
}
