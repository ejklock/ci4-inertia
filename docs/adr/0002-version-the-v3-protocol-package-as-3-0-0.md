---
type: ADR
title: Version the v3-protocol package as 3.0.0
description: The package major follows the Inertia protocol major, so the v3 release is 3.0.0, jumping from 0.2.0.
owner: Evaldo Klock
status: Accepted
timestamp: 2026-10-28T16:05:48Z
---

# 0002. Version the v3-protocol package as 3.0.0

## Context

The package was at 0.2.0 and spoke the Inertia 2.x protocol. The new code speaks protocol v3, and the 2.x client is no longer supported (CHANGELOG 3.0.0). The release needed a version number. The owner answered "versão 3" on 2026-10-28, and the release commit is `2e61111`, tag `v3.0.0`.

## Decision

We will version the package major after the Inertia protocol major: the v3-protocol package is 3.0.0, jumping from 0.2.0 over 0.3.0 and 1.0.0.

Rejected alternatives:

- **0.3.0.**
- **1.0.0.** Neither number carries the protocol major.

## Consequences

**Easier / gained:**

- A user pairs `@inertiajs/*` 3.x with package 3.x without a lookup table. The README Requirements table and `SECURITY.md` list the supported series by the same number.

**Harder / accepted trade-offs:**

- The package never has a 1.x or 2.x series, and the 0.x history stops at 0.2.0.
- A breaking change that does not follow a protocol change has no number that keeps the two majors equal.

**Follow-ups:**

- None.
