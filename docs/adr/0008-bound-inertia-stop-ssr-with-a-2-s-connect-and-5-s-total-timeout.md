---
type: ADR
title: Bound inertia:stop-ssr with a 2 s connect and 5 s total timeout
description: inertia:stop-ssr gives up after 2 s to connect and 5 s in total, where Laravel waits forever.
owner: Evaldo Klock
status: Accepted
timestamp: 2026-10-28T16:05:51Z
---

# 0008. Bound inertia:stop-ssr with a 2 s connect and 5 s total timeout

## Context

`inertia:stop-ssr` sends a shutdown request to the SSR server. Laravel sets no total limit on that request. The command runs in deploy scripts, and a server that accepts the connection and never answers would hang the deploy. The owner chose "Timeout curto". Commit `08bd7d7` (2026-10-27) added the command.

## Decision

We will bound the request with a 2 second connect timeout and a 5 second total timeout (`src/Commands/StopSsr.php:19-20`, set on the cURL handle at `:68-69`). This diverges from Laravel on purpose.

Rejected alternative:

- **No timeout, as Laravel.** It can hang a deploy script.

## Consequences

**Easier / gained:**

- The command always returns, so a deploy script cannot hang on it.

**Harder / accepted trade-offs:**

- A server that needs more than 5 seconds to answer the shutdown request is reported as a failure, although it may still shut down.
- The package differs from inertia-laravel here (ADR 0001).

**Follow-ups:**

- None.

## Verification

**Verification criteria:**

- `tests/Commands/StopSsrTest.php` pins the two timeouts (`:114-115`).
