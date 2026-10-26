# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `InertiaFilter` shares the validation errors that `redirect()->withInput()` flashes as the `errors` prop, so a controller no longer needs `->with('errors', ...)`; an `errors` session array still wins whole.
- `InertiaFilter::version(IncomingRequest $request): string`, a protected hook an extending filter overrides to compute the asset version at runtime; it defaults to the config's `version`.

### Changed

- The stale-version check and the page object now share the version the filter's `version()` returns, resolved once per request before the controller runs; a `service('inertia')->version()` call in a controller is not seen by the check.

## [0.1.0] - 2026-10-26

### Added

- Inertia protocol v2 server adapter for CodeIgniter 4: `service('inertia')` returns the `ResponseFactory`, whose `render()` answers an Inertia request with the page object as JSON and any other request with the root view.
- `Config\Inertia` settings `rootView`, `encryptHistory` and `version`, overridable by an application's own `Config\Inertia`.
- Shared props with `ResponseFactory::share()` and a `share()` override in `InertiaFilter`.
- `InertiaFilter`: shared `errors` prop from session validation errors, `409` reload on a stale asset version, `Vary: X-Inertia`, and `302` to `303` after `PUT`, `PATCH` and `DELETE`.
- `ResponseFactory::version()` and `ResponseFactory::location()` for asset versioning and external redirects.
- `InertiaCsrfFilter`, which answers a failed CSRF check on an Inertia visit with a `303` back to the page and an `error` flash, and `XsrfTokenCookieFilter`, which exposes the CSRF token in the `XSRF-TOKEN` cookie.
- Partial reloads through `X-Inertia-Partial-Component`, `X-Inertia-Partial-Data` and `X-Inertia-Partial-Except`.
- `LazyProp`, `OptionalProp`, `AlwaysProp` and deferred props with groups (`ResponseFactory::defer()`, `DeferProp`).
- Merge props: `ResponseFactory::merge()`, `ResponseFactory::deepMerge()`, `MergeProp` with `prepend()` and `matchOn()`, merge methods on deferred props, and support for the `X-Inertia-Reset` header.
- History encryption (`encryptHistory` setting, `ResponseFactory::encryptHistory()`) and one-shot history clearing (`ResponseFactory::clearHistory()`).
- Test helpers `InertiaTestTrait` and `AssertableInertia` for feature tests.

[Unreleased]: https://github.com/ejklock/ci4-inertia/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/ejklock/ci4-inertia/releases/tag/v0.1.0
