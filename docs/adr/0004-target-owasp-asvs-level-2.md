---
type: ADR
title: Target OWASP ASVS Level 2
description: The package targets OWASP ASVS L2, with a threat pass on every change and a negative test for every security fix.
owner: Evaldo Klock
status: Accepted
timestamp: 2026-10-28T16:05:49Z
---

# 0004. Target OWASP ASVS Level 2

## Context

The owner directed on 2026-10-27 that the package targets OWASP ASVS Level 2. The package prints server-rendered HTML from an SSR server raw, follows redirect targets from the request, and sets cache and cookie headers, so it handles untrusted data in several places. The README Security section lists the concerns that belong to the application: the root view, external redirects, the SSR server, caching and cookies.

## Decision

We will build and review the package to OWASP ASVS Level 2. Every change gets a threat pass with ASVS L2 as the criteria catalog, and every security fix ships with a negative test.

Rejected alternatives:

- **Level 1.** It leaves out the trust placed in the SSR server and the caching concerns, which this package has.
- **Level 3.** It is disproportionate for a library.

## Consequences

**Easier / gained:**

- A review has a fixed catalog to check against.
- The security fixes of 3.0.0 each carry a test that fails on the old behavior (ADR 0006, ADR 0007).

**Harder / accepted trade-offs:**

- Each change carries the cost of the threat pass, including small ones.
- Some L2 concerns belong to the application, so the README Security section lists them for the application to own.

**Follow-ups:**

- `SECURITY.md` and the README Security section do not name ASVS or the threat pass. Opening an issue to say so in those files is the owner's call.

## Verification

**Verification criteria:**

- A security fix is accepted only with a negative test, for example `tests/Support/SiteOriginTest.php` and `tests/Ssr/HttpGatewayTest.php`.
