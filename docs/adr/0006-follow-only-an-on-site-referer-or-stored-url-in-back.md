---
type: ADR
title: Follow only an on-site Referer or stored URL in back()
description: back() and the empty-response redirect follow a Referer or stored URL only on the site origin, unlike Laravel.
owner: Evaldo Klock
status: Accepted
timestamp: 2026-10-28T16:05:50Z
---

# 0006. Follow only an on-site Referer or stored URL in back()

## Context

`back()` and the redirect for an empty Inertia response build a `Location` from the `Referer` header or the stored previous URL. inertia-laravel follows any `Referer`, so an attacker who controls it sends a visitor off the site: an open redirect, found in the security audit as F2. The owner chose on 2026-10-27 to fix it. Commit `9b7be5e` did.

## Decision

We will follow a `Referer` or a stored URL only when its scheme, host and port are the site's own: the base URL's, or a host in `Config\App::$allowedHostnames`. `back()` reads the `Referer`, then the stored URL, then the developer's fallback, then the site root. The empty-response redirect reads the stored URL, then the `Referer`, then the site root, and has no developer fallback. `SiteOrigin::urlOrNull()` (`src/Support/SiteOrigin.php`) makes the check and is shared by `ResponseFactory::back()` (`src/ResponseFactory.php:356`), `InertiaFilter` (`src/Filters/InertiaFilter.php:253`) and the CSRF filter. It also refuses a URL with a backslash or a control character, where PHP and browsers disagree about the host. This diverges from Laravel on purpose.

Rejected alternative:

- **Laravel parity.** It keeps the open redirect.

## Consequences

**Easier / gained:**

- A forged `Referer` cannot take a visitor off the site through `back()`. The same rule serves three call sites.

**Harder / accepted trade-offs:**

- A legitimate cross-origin `Referer` is ignored, so an application that wants it must pass its own `$fallback`, which is used as given.
- The package differs from inertia-laravel here (ADR 0001).

**Follow-ups:**

- None.

## Verification

**Verification criteria:**

- `tests/Support/SiteOriginTest.php` covers the origin rule, including a differing port.
- `tests/Fuzz/SiteOriginTarget.php` fuzzes it, with `composer fuzz:smoke` in CI.
- `tests/InertiaFilterTest.php`, `tests/ResponseFactoryTest.php` and `tests/CsrfFiltersTest.php` were changed in `9b7be5e` for the three call sites.
