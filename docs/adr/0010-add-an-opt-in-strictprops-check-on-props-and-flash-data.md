---
type: ADR
title: Add an opt-in strictProps check on props and flash data
description: An opt-in Config\Inertia::$strictProps refuses, at resolution, any prop or flash value that is not plain data.
owner: Evaldo Klock
status: Accepted
timestamp: 2026-10-28T18:17:14Z
---

# 0010. Add an opt-in strictProps check on props and flash data

## Context

The request came on 2026-10-28 as a handoff from another project, `abby-support-ticket`: a way to make the package refuse any prop or flash value that is not plain data, so an entity or another object never reaches the page. inertia-laravel has no such option, and the package keeps parity with it by default (ADR 0001), so the check had to be opt-in. With the check off, a prop is sent as before: a `JsonSerializable` becomes its `jsonSerialize()` value and any other object is encoded as its public properties.

## Decision

We will add `Config\Inertia::$strictProps`, an opt-in option that is `false` by default (`src/Config/Inertia.php:56`). With it off, the output is the same as in 3.0.0. The owner decided on 2026-10-28:

- **A deep check at resolution, not at call time.** The check runs when the response is built, on the resolved props (`src/PropsResolver.php:484-526`) and on the flash data (`src/InertiaResponse.php:216-217`), at any depth. It does not run in `render()` or `share()`.
- **Accept `stdClass` and check its properties.** The allowed values are null, scalars, arrays, a plain `stdClass` (the exact class, checked inside like an array) and a backed enum that is not `JsonSerializable` (`src/Support/StrictPropValue.php:74-118`). Anything else is refused with `InvalidArgumentException`, which names the dot path and `get_debug_type()`, never the value. A `stdClass` that refers back to an enclosing `stdClass` is refused at its path. A value that nests arrays and `stdClass` objects deeper than `Config\Format::$jsonEncodeDepth`, the depth the JSON response encodes at, is refused too, which ends the walk for an array that holds itself through a reference; the message names the top-level key, or `flash`.
- **Cover flash.** Flash data is checked with the same rule, under paths that start with `flash.`.
- **Option A for a `JsonSerializable` prop type that resolution produced.** When a closure or a `ProvidesInertiaProperty` returns one, it is serialized as with the flag off, and its `jsonSerialize()` value is then checked (`src/PropsResolver.php:500-512`). A non-callable prop type given as it is has no such step and is refused like any other object.

The refusal is thrown outside the rescue (`src/PropsResolver.php:525`), so a rescuable deferred prop never hides it.

Rejected alternatives:

- **A call-time check on `render()` or `share()`.**
- **Refusing every `stdClass`.** It would have broken the package's own `errors` prop.
- **Leaving flash unchecked.**
- **Refusing every user `JsonSerializable` prop type.**
- **Shipping with the known leak**, where a user prop type that also implements `JsonSerializable` passed the check once and the object its `jsonSerialize()` returned reached the page unchecked.

## Consequences

**Easier / gained:**

- An app that turns the option on is told, with the dot path and type, when an object that is not plain data reaches a prop or the flash data.
- An app that leaves it off sees no change from 3.0.0.

**Harder / accepted trade-offs:**

- The package has an option inertia-laravel does not (ADR 0001).
- A `JsonSerializable` prop type that resolution produced passes through its `jsonSerialize()` value, so the check sees that value and not the prop type.

**Follow-ups:**

- None.

## Verification

**Implementation impact:** `src/Config/Inertia.php`, `src/PropsResolver.php`, `src/InertiaResponse.php`, `src/Support/StrictPropValue.php`.

**Verification criteria:**

- `tests/PropsResolverTest.php:1880-1897` pins the 3.0.0 output with the flag off, for a closure inside a `JsonSerializable` that is not a prop type, given directly and returned by a closure.
- `tests/PropsResolverTest.php:1723-1761` pins the refusal of the entity a produced `JsonSerializable` prop type serializes to, and of one given as it is.
- `tests/PropsResolverTest.php:2460` pins that a rescuable deferred prop does not hide a refusal.
- `tests/InertiaResponseTest.php:1428-1447` pins the refusal of flash data at its `flash.` path.
