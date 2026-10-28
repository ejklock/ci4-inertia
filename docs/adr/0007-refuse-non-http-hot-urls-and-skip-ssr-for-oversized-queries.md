---
type: ADR
title: Refuse non-http hot URLs and skip SSR for oversized queries
description: The SSR gateway refuses a hot URL that is not http or https with a host and skips SSR for a query over max_input_vars, unlike Laravel.
owner: Evaldo Klock
status: Accepted
timestamp: 2026-10-28T16:05:51Z
---

# 0007. Refuse non-http hot URLs and skip SSR for oversized queries

## Context

The SSR gateway reads a development URL from a `public/hot` file and posts every page to it. Laravel accepts whatever the file holds. Laravel also parses the query of an excluded-path check without a bound, so a query with more pairs than PHP's `max_input_vars` fails the page with a `500` once a path exclusion is registered. The security audit found both (F3 and F6), and commit `d77e230` (2026-10-27) fixed them as the owner chose.

## Decision

We will refuse a hot URL that is not `http` or `https` with a host and no control character. The gateway fires the connection failure event and the page renders on the client (`src/Ssr/HttpGateway.php:68`, `:382`). We will also skip SSR, so the page renders on the client, for a request whose query has more segments than `max_input_vars` when at least one path exclusion is registered (`src/Ssr/HttpGateway.php:260`). The query is parsed with the configured `arg_separator.input` characters. Both diverge from Laravel on purpose.

Rejected alternative:

- **Laravel parity.** A planted hot file sends a POST to any scheme, and an oversized query turns into a `500`.

## Consequences

**Easier / gained:**

- A bad hot file or a crafted query degrades to client rendering instead of an outbound request to an arbitrary scheme or an error page.

**Harder / accepted trade-offs:**

- An unusual hot URL scheme that worked in Laravel no longer works here.
- A request with a huge query loses SSR when an exclusion is set.
- The package differs from inertia-laravel here (ADR 0001).

**Follow-ups:**

- None.

## Verification

**Verification criteria:**

- `tests/Ssr/HttpGatewayTest.php` covers a hot URL that is not http with a host (`testAHotUrlThatIsNotHttpWithAHostIsRefused`) and a query over `max_input_vars` (`testAQueryWithMoreThanMaxInputVarsPairsSkipsSsr`).
- `tests/Fuzz/ExceptPatternsTarget.php` fuzzes the exclusion check with `max_input_vars` set to 50, run by `composer fuzz:smoke` in CI.
