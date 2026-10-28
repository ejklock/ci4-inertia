---
type: ADR
title: Port inertia-laravel 3.5.2 as the reference and keep parity by default
description: The package ports inertia-laravel 3.5.2 and keeps its defaults, with only the Laravel-only mechanisms left out.
owner: Evaldo Klock
status: Accepted
timestamp: 2026-10-28T16:05:47Z
---

# 0001. Port inertia-laravel 3.5.2 as the reference and keep parity by default

## Context

The package had to move from the Inertia 2.x protocol to v3 (release 3.0.0, 2026-10-28). inertia-laravel 3.5.2 is the maintained adapter for that protocol and already solves each part of it. The owner set the rule on 2026-10-28: "Inspire-se no código do pacote laravel. Não reinvente a roda", and asked for "paridade total". The 3.x work in `git log --oneline v0.2.0..v3.0.0` is 27 commits. 15 are ports of a Laravel part, titled with "laravel" (for example `0e1d655` the props resolver, `6b544bf` once props, `b6e247a` SSR); the other 12 are 4 other features, 3 fixes, 2 docs, 1 test, 1 CI and the release commit.

## Decision

We will port inertia-laravel 3.5.2 to CodeIgniter idioms and keep parity with it by default. Where Laravel has a default, the package keeps it as Laravel has it. The owner confirmed three on 2026-10-28:

- `encryptHistory` stays `false` (`src/Config/Inertia.php:28`).
- `InertiaFilter` turns a `302` into a `303` only after a `PUT`, `PATCH` or `DELETE` on an Inertia request (CHANGELOG 3.0.0, UPGRADING step 6).
- `OnceProp` is mutable: `once()`, `as()`, `until()` and `fresh()` change the instance (`src/OnceProp.php:12`).

Not ported, because each is a Laravel-only mechanism: `Route::inertia`, `Request::macro('inertia')`, `Macroable`, the `AssertableJson` fluent surface (a candidate for 3.1), the Blade directives, and `EnsureDeferredCallbacksRun`. The README "Not supported" list names each one, with a CodeIgniter replacement for all but `EnsureDeferredCallbacksRun`, which "has no CodeIgniter counterpart".

Rejected alternatives:

- **An own design.** It would re-solve what Laravel's adapter already solved.
- **A partial port.** It would go against the owner's "paridade total".

## Consequences

**Easier / gained:**

- A change has a reference to be checked against.

**Harder / accepted trade-offs:**

- Laravel's defaults are inherited even where another default would suit CodeIgniter better.
- Every Laravel release has to be read for changes to port.
- A deliberate divergence needs its own ADR. The ones so far are ADR 0006, 0007 and 0008.

**Follow-ups:**

- The `AssertableJson` surface is a candidate for 3.1.
