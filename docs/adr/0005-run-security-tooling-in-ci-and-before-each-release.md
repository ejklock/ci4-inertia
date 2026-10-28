---
type: ADR
title: Run security tooling in CI and before each release
description: CI runs composer audit, Psalm taint analysis, Scorecard, Dependabot and a php-fuzzer smoke run, with a long fuzz run before each release.
owner: Evaldo Klock
status: Accepted
timestamp: 2026-10-28T16:05:50Z
---

# 0005. Run security tooling in CI and before each release

## Context

With the ASVS L2 target (ADR 0004) the package needs automatic checks that do not depend on a reviewer remembering them. Commits `bee2a1a` and `1575436` (2026-10-27) added them. The owner chose four tool groups on 2026-10-27: dependency audit, Psalm taint, Scorecard with Dependabot, and php-fuzzer.

## Decision

We will run security tooling in CI and before each release:

- `composer audit --abandoned=fail`, which fails on an advisory or an abandoned package (`.github/workflows/ci.yml:86`).
- Psalm taint analysis, `composer analyze:taint`, with the request getters declared as taint sources (`ci.yml:88`, `psalm.taint.xml`, `composer.json:67`).
- OpenSSF Scorecard (`.github/workflows/scorecard.yml`).
- Dependabot for Composer and GitHub Actions, weekly with a 7-day cooldown (`.github/dependabot.yml`).
- php-fuzzer on two targets in `tests/Fuzz/`: a smoke run in CI (`composer fuzz:smoke`, `ci.yml:92`) and a long run before each release (`composer fuzz`). `CONTRIBUTING.md` describes each script.

Rejected alternative:

- **Relying on `se-gates` alone.**

## Consequences

**Easier / gained:**

- An advisory, a tainted flow or a crash on odd input fails the build before it reaches a release.

**Harder / accepted trade-offs:**

- CI takes longer, and the long fuzz run is a manual step before a release.
- Taint analysis does not trace the SSR hot file, which is documented as trusted deploy state (README Security).
- Dependabot opens pull requests that someone must review.

**Follow-ups:**

- None.

## Verification

**Verification criteria:**

- `composer audit --abandoned=fail`, `composer analyze:taint` and `composer fuzz:smoke` run in `ci.yml` and fail the build on a finding.
- `composer fuzz:check` fails when a crash file or a `CRASH` or `Fatal error` line exists in the fuzz logs.
