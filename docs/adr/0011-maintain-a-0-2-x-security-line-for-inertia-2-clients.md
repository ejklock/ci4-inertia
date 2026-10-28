---
type: ADR
title: Maintain a 0.2.x security line for Inertia 2 clients
description: The 0.2.x series is a maintenance line that takes security fixes only, as patch versions, for apps on an Inertia 2 client.
owner: Evaldo Klock
status: Accepted
timestamp: 2026-10-28T22:26:16Z
---

# 0011. Maintain a 0.2.x security line for Inertia 2 clients

## Context

On 2026-10-28 the owner asked whether the 3.x improvements could reach the 0.2 line without requiring the Inertia 3 client. 3.0.0 speaks protocol v3 and drops the Inertia 2 client (ADR [0002](0002-version-the-v3-protocol-package-as-3-0-0.md)), so an app on an Inertia 2 client stays on 0.2.0, and 0.2.0 had two security gaps:

- `InertiaCsrfFilter` followed a Referer or stored previous URL that held a backslash or a control character. A tab or a backslash could redirect off-site, because the URL parser and the browser disagree on such a URL. A `\x00`, `\x1f`, `\x7f` or a newline made `redirect()->to()` throw, a 500. 3.x closes the same class of gap in `src/Support/SiteOrigin.php:27-29` (ADR [0006](0006-follow-only-an-on-site-referer-or-stored-url-in-back.md)).
- The prop guard checked only the top level, so an object nested in an array, or the value a prop object resolves to, reached the page unchecked.

## Decision

We will take option B: keep the 0.2.x series as a maintenance line that takes security fixes only, released as patch versions, with 0.2.1 first. The owner decided on 2026-10-28:

- **Branches.** Each fix is made on a branch cut from the last 0.2 tag and named `fix/ek-v0.2.N-<topic>`. The 0.2.1 branch is `fix/ek-v0.2.1-security`, cut from `v0.2.0`. The CI workflow on that branch runs on pushes to `fix/ek-v0.2.*`.
- **No backport of a feature.** Nothing from 3.x moves to the line except a security fix.
- **The deep guard is on by default in 0.2.1.** Inside an array a prop may hold only null, scalars and arrays. The value a prop object resolves to may also hold an exact `stdClass`. The walk stops at `Config\Format::$jsonEncodeDepth` levels.
- **A patch carries a narrowing guard.** The guard refuses an app that nested an object in a prop array, which 0.2.0 accepted. It closes a security gap, so it ships in a patch release and not behind an option. ADR [0001](0001-port-inertia-laravel-3-5-2-as-the-reference-and-keep-parity-by-default.md) and its Laravel-parity rule govern 3.x, not the 0.2 line, which predates the 3.x port.

This amends one consequence of ADR 0002, which says that the 0.x history stops at 0.2.0. The 0.x history now continues with security patches.

Rejected alternatives:

- **A. Test 3.x against an Inertia 2.3.x client and document that 3.x serves 2.3+.** It needs a harness with the real client, a redirect with a fragment would need its own option and ADR, and it does not help an app on a client older than 2.3.
- **C. A 0.x line that ports inertia-laravel 2.0.28, which Laravel still maintains.** It gives parity with Laravel's 2.x line, but in practice it is a second rewrite the size of 3.0.

## Consequences

**Easier / gained:**

- An app on an Inertia 2 client gets the two security fixes without moving to the Inertia 3 client.
- `SECURITY.md` names 0.2.x as a series that receives security fixes.

**Harder / accepted trade-offs:**

- The package keeps a second line to maintain, with its own branches, tags and CI runs.
- The deep guard can refuse an app that nested an object in a prop array, and it does so in a patch release.
- Only the latest 0.2 release receives fixes, as for every supported series.

**Follow-ups:**

- None.
