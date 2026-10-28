# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- `InertiaCsrfFilter` now answers a refused Inertia visit whose `Referer` or stored previous URL holds a backslash or a control character with a 303 to the next on-site URL. On 0.2.0 the tab and backslash cases redirected to that URL, which PHP and a browser read as two different hosts; `\x00`, `\x1f`, `\x7f` and a newline made `redirect()->to()` throw `InvalidArgumentException`, a 500.
- `render()` and `share()` now refuse an object nested at any depth inside an array prop, and the value a prop object resolves to is checked the same way (a plain `stdClass` is allowed there), so an entity never reaches the page through a nested array or a callback. An app that nests an object in a prop now gets an `InvalidArgumentException` naming the dot path. An array or `stdClass` nested deeper than the app's `Config\Format::$jsonEncodeDepth` (512 by default), the depth its JSON response encodes at, is refused too, naming the limit and only the top-level prop key; that also ends the walk on an array that holds itself through a reference.

## [0.2.0] - 2026-10-26

### Added

- `Ejklock\Ci4Inertia\Inertia`, a static shortcut for `service('inertia')` with the same methods as `ResponseFactory`: `Inertia::render('Users/Index', [...])`.
- `InertiaFilter` shares the validation errors that `redirect()->withInput()` flashes as the `errors` prop, so a controller no longer needs `->with('errors', ...)`; an `errors` session array still wins whole.
- `InertiaFilter::version(IncomingRequest $request): string`, a protected hook an extending filter overrides to compute the asset version at runtime; it defaults to the config's `version`.
- A README quickstart from a fresh CodeIgniter app to a first Vue page, with the Inertia client pinned to 2.x.

### Changed

- The stale-version check and the page object now share the version the filter's `version()` returns, resolved at most once per request, only when the stale check of an Inertia `GET` or a render needs it; a `service('inertia')->version()` call in a controller is not seen by the check.

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

[Unreleased]: https://github.com/ejklock/ci4-inertia/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/ejklock/ci4-inertia/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/ejklock/ci4-inertia/releases/tag/v0.1.0
