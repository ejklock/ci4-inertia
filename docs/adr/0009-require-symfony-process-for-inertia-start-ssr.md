---
type: ADR
title: Require symfony/process for inertia:start-ssr
description: symfony/process is a required dependency so inertia:start-ssr works on every install.
owner: Evaldo Klock
status: Accepted
timestamp: 2026-10-28T16:05:52Z
---

# 0009. Require symfony/process for inertia:start-ssr

## Context

`inertia:start-ssr` runs the SSR bundle with the configured runtime as a child process. Commit `08bd7d7` (2026-10-27) added it with `symfony/process`, and the owner chose "Exigir symfony/process".

## Decision

We will require `symfony/process` (`^7.2 || ^8.0`, `composer.json:28`) in `require`. `src/Commands/StartSsr.php` uses `Process` and `ExecutableFinder` from it.

Rejected alternatives:

- **A suggest-only dependency with a runtime check.**
- **`proc_open` by hand.**

## Consequences

**Easier / gained:**

- `inertia:start-ssr` needs no runtime check for a missing dependency.
- The process handling is a maintained library's, covered by `composer audit` and Dependabot (ADR 0005).

**Harder / accepted trade-offs:**

- Every install pulls `symfony/process`, including those that never use SSR.
- The constraint follows Symfony majors, so a new major needs a constraint change.

**Follow-ups:**

- None.

## Verification

**Verification criteria:**

- `composer.json` lists `symfony/process` under `require`, and `tests/Commands/StartSsrTest.php` covers the command.
