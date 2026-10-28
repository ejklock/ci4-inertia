---
type: ADR
title: Print the root view page only through inertia_app()
description: The root view prints the page through inertia_app(), a JSON script tag encoded with JSON_HEX_TAG.
owner: Evaldo Klock
status: Accepted
timestamp: 2026-10-28T16:05:49Z
---

# 0003. Print the root view page only through inertia_app()

## Context

The 2.x root view put the page in a `data-page` attribute through `esc()`. The v3 client reads the page from a script tag. The security audit (finding F1) showed the risk in a hand-written form: a prop value containing `</script>` closes the tag and runs markup, and `esc($page, 'attr')` escapes an array element by element, so it prints `Array`. Commit `da4cecd` (2026-10-26) moved the root view to the `inertia_app()` helper, with the page "encoded with JSON_HEX_TAG as inertia-laravel 3.x does".

## Decision

We will print the page in the root view only through `inertia_app()`, which emits `<script data-page="app" type="application/json">` with the page encoded with `JSON_HEX_TAG`, followed by the mount element (`src/Helpers/inertia_helper.php:23-35`). The README documents one manual form, and it carries the same flag.

Rejected alternatives:

- **The 2.x `data-page` attribute with `esc()`.** `esc()` escapes an array element by element, so a page array prints as `Array`.
- **A hand-written `json_encode`.** Without `JSON_HEX_TAG` a prop value can close the script tag. Leaving the flag to every application leaves the audit finding open.

## Consequences

**Easier / gained:**

- One place holds the encoding and the flag, so an application cannot forget it. The same helper dispatches SSR and prints its body.

**Harder / accepted trade-offs:**

- An application that wants another root view shape either uses the helper or copies the documented manual form with its flag.

**Follow-ups:**

- None.

## Verification

**Implementation impact:** `src/Helpers/inertia_helper.php`, the root view example in `README.md`.

**Verification criteria:**

- `scripts/readme.test.mjs` fails when a README root view block prints the page through `esc()` into `data-page`, or calls `json_encode` on the page without `JSON_HEX_TAG`, or does not call `inertia_app($page)`. It also fails when the README has no root view block at all.
- `tests/InertiaHelperTest.php::testAScriptClosingSequenceInAPropCannotEndTheTagEarly` covers the helper.
