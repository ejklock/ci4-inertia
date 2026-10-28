# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.1.0] - 2026-10-28

### Added

- `Config\Inertia::$strictProps`, off by default, as inertia-laravel has no such option; with it off the output is the same as in 3.0.0. When on, building a page checks the resolved props and the flash data, at any depth, and throws `InvalidArgumentException` for any value that is not null, a scalar, an array, a plain `stdClass` (checked inside like an array) or a backed enum that is not `JsonSerializable`. The message names the dot path, which starts with `flash.` for flash data, and `get_debug_type()` of the value, never the value itself. A `JsonSerializable` prop type that a closure or a `ProvidesInertiaProperty` returns is sent as its `jsonSerialize()` value, as with the flag off, and that value is checked with the same rule; a non-callable prop type given as it is gets refused. A `stdClass` that refers back to an enclosing `stdClass` is refused at its path. The check runs when the response is built, not when `render()` or `share()` is called, and the refusal is thrown outside the rescue, so a rescuable deferred prop never hides it.

## [3.0.0] - 2026-10-28

This release ports inertia-laravel 3.5.2 for the Inertia v3 client. To upgrade from 0.2.x, follow [UPGRADING.md](UPGRADING.md).

### Added

- Inertia protocol v3. The root view prints the page with `inertia_app($page)` as a `<script data-page="app" type="application/json">` element and a `<div id="app">` to mount on. `inertia_head($page)` prints the head an SSR server rendered, or a fallback. The 2.x client is no longer supported.
- Props resolve through a `PropsResolver` ported from inertia-laravel. A closure or an invokable object is a prop and is called only when the prop is sent. A partial reload filters nested props by dot path.
- Once props: `Inertia::once()`, `Inertia::shareOnce()` and `OnceProp`, with `as()`, `until()` and `fresh()`. The page lists them under `onceProps`, and the client keeps them across visits. `MergeProp`, `DeferProp` and `OptionalProp` can also be marked with `once()`.
- Scroll props: `Inertia::scroll()` and `ScrollProp` for infinite scroll. Position metadata comes from a `ProvidesScrollMetadata`, a callable, or the pager `paginate()` stored. The page lists the prop under `scrollProps`.
- Rescued deferred props: `Inertia::defer($callback, $group, rescue: true)` logs a throw from the callback and leaves the prop out. The page lists the prop under `rescuedProps`.
- Flash data: `Inertia::flash()`, `getFlashed()` and `pullFlashed()` store one-time data the next page carries under `flash`. The filter flashes it again on a redirect. `preserveFragment()` sets `preserveFragment` on the next page.
- `Inertia::back()` redirects to the `Referer`, the stored previous URL, a fallback, or `/`. See Changed for the origin check.
- Big integers: `Config\Inertia::$preserveBigIntegers` and `InertiaResponse::preserveBigIntegers()` send integers beyond 2^53 - 1 as `{"$bigint": "<digits>"}` and set `preserveBigIntegers: true` on the page.
- Page-existence check: `Config\Inertia::$ensurePagesExist` (off) makes `render()` throw `ComponentNotFoundException` when no page file matches. `$pagePaths` and `$pageExtensions` say where to look. `$ensurePagesExistInTests` (on) makes `AssertableInertia::component()` check the file too.
- Exception pages: `Inertia::handleExceptionsUsing()` registers a callback that answers an uncaught exception with an Inertia page, through an `ExceptionResponse`. It runs only when `Config\Exceptions::handler()` returns an `InertiaExceptionHandler`. A `null` result and a CLI request go to CodeIgniter's handler.
- Server-side rendering. `HttpGateway` posts the page to an SSR server, or to the Vite dev server when the hot file exists, and the root view prints the result. Without SSR, a bundle, or a healthy answer, the page renders on the client. A failed render fires `SsrRenderFailed` and throws `SsrException` when `$ssrThrowOnError` is on. New settings: `$ssrEnabled`, `$ssrUrl`, `$ssrHotUrl`, `$ssrHotFile`, `$ssrTimeout`, `$ssrEnsureBundleExists`, `$ssrBundle`, `$ssrRuntime`, `$ssrEnsureRuntimeExists` and `$ssrThrowOnError`. `Inertia::disableSsr()`, `withoutSsr()` and `configureSsrRequestUsing()`, and the filter's `$withoutSsr` list, switch SSR off, skip paths and adjust the request. The services `inertiaSsrGateway` and `inertiaSsrState` are new.
- SSR commands: `inertia:start-ssr` runs the bundle with the configured runtime through `symfony/process`, `inertia:stop-ssr` stops the server (`--graceful` exits `0` when none runs), and `inertia:check-ssr` reports its health.
- Property providers. A `ProvidesInertiaProperty` prop is converted with its key, its sibling props and the request. A `ProvidesInertiaProperties` value at a numeric key expands into keyed props. `share()` accepts a provider.
- Shared prop keys: the page lists the top-level keys of the shared props under `sharedProps`. `Config\Inertia::$exposeSharedPropKeys` (on) turns that off.
- Factory surface and helpers: `getShared()`, `flushShared()`, `getVersion()`, `setRootView()`, `resolveUrlUsing()`, `transformComponentUsing()`, `optional()` and `always()` on the factory and on `Inertia`. `render()` takes a backed or pure enum as the component, and `location()` takes a `RedirectResponse`. `InertiaResponse` gains `with()`, `withViewData()`, `rootView()` and `flash()`. The helpers `inertia()` and `inertia_location()` reach the factory.
- Filters: `EncryptHistoryFilter` turns history encryption on for a route group. `EnsureGetOnRedirectFilter` turns a `302` into `303` after an Inertia `PUT`, `PATCH` or `DELETE` without the rest of `InertiaFilter`.
- `InertiaFilter` answers an empty Inertia `200` with a redirect back, and a redirect to a URL with a `#fragment` with a `409` and `X-Inertia-Redirect`. It skips browser prefetches (`X-Moz`, `Purpose`, `Sec-Purpose`).
- `Config\Inertia::$storePreviousUrl` (on) records a rendered Inertia `GET` as CodeIgniter's previous URL, so `redirect()->back()` returns to the page the visitor is on.
- The `errors` prop honors the `X-Inertia-Error-Bag` header. An extending filter sets `$withAllErrors` to send every message of a field.
- Test helpers. `AssertableInertia` gains `reload()`, `reloadOnly()`, `reloadExcept()`, `loadDeferredProps()`, `hasAll()`, `missingAll()`, `hasFlash()` and `missingFlash()`. `InertiaTestTrait` gains `inertiaPage()`, `inertiaProp()`, `assertInertiaFlash()` and `assertInertiaFlashMissing()`. A reload needs `FeatureTestTrait`. Paths try a literal dotted key first.
- `Support\Header` holds the Inertia header names of inertia-laravel 3.x.
- Tooling. CI runs `composer audit --abandoned=fail`, a Psalm taint analysis (`composer analyze:taint`), the SSR gateway tests under other `arg_separator.input` and `max_input_vars` settings (`composer test:ini-variants`), and a php-fuzzer smoke run (`composer fuzz:smoke`). `composer fuzz` is the long run for before a release. An OpenSSF Scorecard workflow and Dependabot updates for Composer and GitHub Actions were added.

### Changed

- **Breaking:** the root view receives the page as an array in `$page`, not as a JSON string, and prints it with `inertia_app($page)`. The 2.x `data-page` attribute is gone. See [UPGRADING.md, step 3](UPGRADING.md#step-3-update-the-root-view).
- **Breaking:** the Inertia client must be 3.x. A 2.x client cannot read the page. See [UPGRADING.md, step 2](UPGRADING.md#step-2-update-the-client-packages).
- **Breaking:** `symfony/process` (`^7.2 || ^8.0`) is a required dependency, for the `inertia:start-ssr` command. See [UPGRADING.md, step 1](UPGRADING.md#step-1-update-the-package).
- **Breaking:** `InertiaTestTrait` and `AssertableInertia` read the page from the `<script data-page type="application/json">` element, parsed as UTF-8, and no longer from a `data-page` attribute. `AssertableInertia::component()` also checks that the page file exists unless `$ensurePagesExistInTests` is off. See [UPGRADING.md, step 8](UPGRADING.md#step-8-update-the-tests).
- **Breaking:** the prop classes follow inertia-laravel. `resolve()` is replaced by `__invoke()`, and `new MergeProp()` and `new DeferProp()` take new arguments. Prop values are no longer validated, so a closure is a valid prop. Items of `X-Inertia-Partial-Data` and `X-Inertia-Partial-Except` are no longer trimmed. Only closures and invokable objects are called as prop callbacks. See [UPGRADING.md, step 4](UPGRADING.md#step-4-replace-the-removed-prop-classes).
- **Breaking:** a prop key with dots, such as `auth.user`, is now a path and is unpacked into nested props: the client gets `{"auth": {"user": ...}}`. In 0.2.x `share('auth.user', ...)` and a render prop `'auth.user' => ...` were sent as the flat key `{"auth.user": ...}`. `share()` with a string key sets the value by dot path. See [UPGRADING.md, step 10](UPGRADING.md#step-10-read-dotted-prop-keys-as-nested-props).
- **Breaking:** `MergeProp::matchOn()` and `DeferProp::matchOn()` take one field name or an array of names, no longer a variadic list. A second string argument is ignored. See [UPGRADING.md, step 5](UPGRADING.md#step-5-pass-match-fields-as-an-array).
- **Breaking:** `InertiaFilter` turns a `302` into `303` only for an Inertia `PUT`, `PATCH` or `DELETE`. A non-Inertia request keeps its `302`. See [UPGRADING.md, step 6](UPGRADING.md#step-6-expect-a-302-for-a-non-inertia-put-patch-or-delete).
- **Breaking:** the shared `errors` prop sends one message per field, not the stored value as it is. An extending filter sets `$withAllErrors` to send every message. A request without a session cookie gets an empty object and no session is started. See [UPGRADING.md, step 7](UPGRADING.md#step-7-read-one-error-message-per-field).
- **Divergence from inertia-laravel:** `Inertia::back()`, `ResponseFactory::back()` and the redirect `InertiaFilter` sends for an empty Inertia `200` follow a `Referer` or a stored previous URL only when its scheme, host and port are the site's own. Else they use the next source, the fallback, or the site root. A URL with a backslash or a control character is never on the site. See [UPGRADING.md, step 9](UPGRADING.md#step-9-review-redirects-back).
- The page object's `url` is the request URI the client sent, with its subfolder, index page, trailing slash and query as sent. It was the route path plus the query. A leading run of slashes collapses to one.
- `InertiaFilter::applyVaryHeader()` is public and static, and adds `Vary: X-Inertia` only when it is missing. A subclass that declares its own non-static `applyVaryHeader()` must rename it.
- `InertiaCsrfFilter` shares its same-origin rule with `back()` through the internal `SiteOrigin` class, so it also refuses a `Referer` with a backslash or a control character.
- The SSR request waits at most 10 seconds to connect and 30 seconds in total, or `$ssrTimeout` seconds when set. These are Laravel's HTTP client defaults, which inertia-laravel relies on.
- **Divergence from inertia-laravel:** `inertia:stop-ssr` waits 2 seconds to connect and 5 in total. inertia-laravel sets no total limit, and curl's own default applies to its connect phase.
- **Divergence from inertia-laravel:** the SSR gateway refuses a hot URL that is not an `http` or `https` URL with a host, or that holds a control character. It fires `SsrRenderFailed`, and renders on the client unless `$ssrThrowOnError` is on.
- **Divergence from inertia-laravel:** the SSR gateway skips SSR for a request whose query has more pairs than PHP's `max_input_vars`, split by every `arg_separator.input` character, instead of failing the page. The check runs when an SSR path exclusion is registered and the path does not match it. A path-exclusion pattern is matched against the URL the client requested, with its query sorted by key.
- `Config\Inertia` adds the settings named under Added. The defaults that change an existing app are `$storePreviousUrl` (on) and `$ensurePagesExistInTests` (on). `$ssrEnabled` is on, but nothing renders on the server unless an SSR bundle exists or the Vite hot file does.

### Removed

- **Breaking:** `LazyProp`. A plain closure is resolved on a full render and on a partial reload that names it. Use `Inertia::optional()` to leave the prop out of the first render. See [UPGRADING.md, step 4](UPGRADING.md#step-4-replace-the-removed-prop-classes).
- **Breaking:** `ResolvableProp`, `MergeState` and `MergeMode`. See [UPGRADING.md, step 4](UPGRADING.md#step-4-replace-the-removed-prop-classes).
- The 2.x `data-page` attribute in the root view. See Changed.

### Fixed

- The page `url` keeps the trailing slash the client requested.

### Security

- `Inertia::back()` and the empty-response redirect cannot send a visitor to another site through a forged `Referer` or a stored URL. See Changed.
- The SSR gateway posts the page only to an `http` or `https` hot URL with a host.
- `inertia_app()` encodes the page with `JSON_HEX_TAG`, so a prop value cannot close the script tag.

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

[Unreleased]: https://github.com/ejklock/ci4-inertia/compare/v3.1.0...HEAD
[3.1.0]: https://github.com/ejklock/ci4-inertia/compare/v3.0.0...v3.1.0
[3.0.0]: https://github.com/ejklock/ci4-inertia/compare/v0.2.0...v3.0.0
[0.2.0]: https://github.com/ejklock/ci4-inertia/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/ejklock/ci4-inertia/releases/tag/v0.1.0
