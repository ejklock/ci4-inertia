# ci4-inertia

The CodeIgniter 4 adapter for Inertia.js (v3 protocol), for developers who build a CodeIgniter 4 application with an Inertia client. It is a Composer package, `ejklock/ci4-inertia`, in the namespace `Ejklock\Ci4Inertia`: a core that builds the page object (`ResponseFactory`, `InertiaResponse`, `PropsResolver`), CodeIgniter filters, an optional SSR gateway with its commands, and test helpers.

## Commands

The Composer commands run in the dev container through `sh bin/dev`; the other commands run on the host.

| Task | Command |
|---|---|
| Test | `sh bin/dev composer test` |
| Static analysis | `sh bin/dev composer analyze` |
| Code style | `sh bin/dev composer cs` |
| Coverage gate | `sh bin/dev composer test:coverage-gate` |
| Taint | `sh bin/dev composer analyze:taint` |
| Ini variants | `sh bin/dev composer test:ini-variants` |
| Fuzz smoke | `sh bin/dev composer fuzz:smoke` |
| Mutation | `sh bin/dev composer mutation` (CI runs `composer mutation`, `--min-msi=80`) |
| Dependency audit | `sh bin/dev composer audit --abandoned=fail` |
| README doc-test | `node --test scripts/*.test.mjs` |
| Docs | `living-docs check docs` |
| Gate | `se-gates check` |

## The reference feature

`src/Support/SiteOrigin.php`, with `tests/Support/SiteOriginTest.php` and `tests/Fuzz/SiteOriginTarget.php`. It shows a `final` `@internal` helper with a small surface, negative tests for hostile input, and a fuzz target.

## Constraints the gate cannot check

1. Port every feature from the inertia-laravel 3.5.2 code, and diverge only to close a security gap, with an ADR. Reason: parity with Laravel is the product (ADR 0001).
2. Behavior beyond Laravel ships as an opt-in config option, and its default stays at Laravel parity. Reason: an app moving from Laravel meets no surprise.
3. A change that alters a recorded decision or a drawn structure updates its ADR or architecture view in the same commit. Reason: the docs must reflect the code.

## Artifacts

English for code, comments, docs and commits. This matches the existing files.

## Living Docs

```
enforcement: guided
onboarded: 2026-10-28
```

Run `living-docs check docs` before every commit; it must exit 0, and no record exists unindexed.

## Docs index

- [docs/index.md](docs/index.md)
- [docs/adr/index.md](docs/adr/index.md)
- [docs/architecture/index.md](docs/architecture/index.md)
- [README.md](README.md)
- [UPGRADING.md](UPGRADING.md)
- [CHANGELOG.md](CHANGELOG.md)
- [SECURITY.md](SECURITY.md)
- [CONTRIBUTING.md](CONTRIBUTING.md)

## Commit authorship

Commits are authored as `Evaldo Klock`, with the email from `git config user.email`. Write no email address in a committed file, because the repository is public.
