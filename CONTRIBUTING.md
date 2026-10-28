# Contributing

Thanks for helping. This guide covers how to set up, test and send a change.

By taking part you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md). To report a vulnerability, use [SECURITY.md](SECURITY.md), not a public issue.

## Development environment

Development runs in Docker through `bin/dev`. You do not need PHP or Composer on your machine.

```bash
sh bin/dev up                  # build and start the container
sh bin/dev composer install    # install dependencies
sh bin/dev test                # run the test suite
sh bin/dev composer <script>   # run any composer script
sh bin/dev exec app <command>  # run a command in the app container
sh bin/dev sh                  # open a shell in the container
sh bin/dev ps                  # show the container state
sh bin/dev down                # stop the container
```

A git worktree gets its own container project, so several checkouts do not collide.

## Composer scripts

Run each with `sh bin/dev composer <name>`.

| Script | What it does |
| --- | --- |
| `test` | PHPUnit without coverage. |
| `test:coverage` | PHPUnit with coverage; writes `build/logs/clover.xml`. |
| `coverage:check` | Fails when line coverage in `build/logs/clover.xml` is below 80%. |
| `test:coverage-gate` | `test:coverage` then `coverage:check`. |
| `mutation` | Infection mutation testing; fails when the mutation score indicator (MSI) is below 80%. |
| `analyze` | PHPStan, Psalm and Rector (dry run). |
| `analyze:taint` | Psalm taint analysis of `src/`, with the request getters declared as taint sources in `stubs/psalm/codeigniter-taint.phpstub`. |
| `test:ini-variants` | The SSR gateway tests under `arg_separator.input=';'`, `arg_separator.input='&;'` and `max_input_vars=0`, which `ini_set` cannot reach. |
| `cs` | PHP CS Fixer in dry-run mode; shows the diff and fails on a violation. |
| `cs:fix` | PHP CS Fixer; fixes the code style. |

## Quality bars

A pull request must pass CI, which enforces:

- all tests green, on PHP 8.2 to 8.5 with the lowest and the highest dependencies;
- line coverage of at least 80%;
- mutation score indicator (MSI) of at least 80%;
- `analyze` and `analyze:taint` clean;
- `composer audit --abandoned=fail` reports no advisory and no abandoned package;
- `test:ini-variants` green;
- `cs` clean.

Docs-only changes (README, CHANGELOG, CONTRIBUTING, CODE_OF_CONDUCT, SECURITY and the issue and pull request templates) skip the code jobs.

Write the test for a change before the code, and show it failing first. Test what a caller observes, not that a method was called. When a change alters behavior a document describes, update the document in the same pull request, and add a line under `Unreleased` in [CHANGELOG.md](CHANGELOG.md).

## Commits

Use [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`, `ci:`. Keep the subject short and in the imperative mood.

## Pull request flow

1. Open an issue first for anything larger than a small fix, so the direction is agreed.
2. Fork the repository and create a branch from `main`.
3. Make the change with its tests, and run `test`, `analyze` and `cs` locally.
4. Open a pull request against `main` and fill in the template.
5. Address review comments with new commits; a maintainer merges when CI is green.
