# ci4-inertia

[![CI](https://github.com/ejklock/ci4-inertia/actions/workflows/ci.yml/badge.svg)](https://github.com/ejklock/ci4-inertia/actions/workflows/ci.yml)
[![Packagist version](https://img.shields.io/packagist/v/ejklock/ci4-inertia.svg)](https://packagist.org/packages/ejklock/ci4-inertia)
[![PHP version](https://img.shields.io/packagist/php-v/ejklock/ci4-inertia.svg)](https://packagist.org/packages/ejklock/ci4-inertia)
[![License](https://img.shields.io/packagist/l/ejklock/ci4-inertia.svg)](LICENSE)

Server-side [Inertia.js](https://inertiajs.com) adapter for CodeIgniter 4. Build a single-page app with Vue, React or Svelte while keeping CodeIgniter routing, controllers, sessions and validation.

## Requirements

| Package | Version |
| --- | --- |
| PHP | ^8.2 (tested on 8.2, 8.3, 8.4 and 8.5) |
| codeigniter4/framework | ^4.7 (lowest tested: 4.7.4) |
| Inertia protocol | v2 (client `@inertiajs/*` 2.x) |

## Installation

```bash
composer require ejklock/ci4-inertia
```

The package registers the `inertia` service through CodeIgniter's package discovery. Nothing else is published into your app.

## Root view

A first visit (or a hard reload) returns an HTML page. Create the root view `app/Views/app.php`. It receives the page object as a JSON string in `$page`; put it, attribute-escaped, in `data-page`:

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My app</title>
    <!-- load your built assets here -->
</head>
<body>
<div id="app" data-page="<?= esc($page, 'attr') ?>"></div>
</body>
</html>
```

The view is named `app` by default. Change it with the `rootView` setting (see [Configuration](#configuration)).

## Filters

Register the filter in `app/Config/Filters.php`.

```php
use Ejklock\Ci4Inertia\Filters\InertiaFilter;

public array $aliases = [
    // ...
    'inertia' => InertiaFilter::class,
];

public array $globals = [
    'before' => ['inertia'],
    'after'  => ['inertia'],
];
```

`InertiaFilter`:

- shares the `errors` prop on every render (see [Validation errors and redirects](#validation-errors-and-redirects));
- answers a `GET` Inertia visit whose `X-Inertia-Version` is stale with a `409` and `X-Inertia-Location` (see [Asset versioning](#asset-versioning));
- adds `Vary: X-Inertia` to every response;
- turns a `302` into a `303` after a `PUT`, `PATCH` or `DELETE`, so the browser follows the redirect with a `GET`.

In CodeIgniter 4.7 with the default filter order (`Feature::$oldFilterOrder = false`), the `before` filters in `$globals` run ahead of `$methods`, `$filters` and route filters. An authentication or authorization filter that must run first therefore goes before `'inertia'` in `$globals['before']`; a denial from a filter listed earlier ends the chain before `InertiaFilter` runs. A `$methods`, `$filters` or route auth filter runs after `InertiaFilter`'s `before()`.

### CSRF

If you use CodeIgniter's CSRF protection, replace the stock `csrf` alias with `InertiaCsrfFilter` and add `XsrfTokenCookieFilter`:

```php
use Ejklock\Ci4Inertia\Filters\InertiaCsrfFilter;
use Ejklock\Ci4Inertia\Filters\XsrfTokenCookieFilter;

public array $aliases = [
    // ...
    'csrf'     => InertiaCsrfFilter::class,
    'xsrfcook' => XsrfTokenCookieFilter::class,
];

public array $globals = [
    'before' => ['inertia', 'csrf'],
    'after'  => ['inertia', 'xsrfcook'],
];
```

Why:

- `InertiaCsrfFilter` checks the token like the stock filter. When the check fails on an Inertia visit it answers with a `303` back to the page the visit came from (an on-site `Referer`, else the previous page, else the site root) and flashes an `error` message, so the page can ask the user to retry. Every other request keeps the stock behavior: a `SecurityException` and the `403` error screen. The flash text comes from the `Csrf.expired` language line, which you can override in your app.
- `XsrfTokenCookieFilter` writes the CSRF token into a script-readable `XSRF-TOKEN` cookie on every response with a status below 400. The HTTP client Inertia uses (axios) reads that cookie and sends it back in the `X-XSRF-TOKEN` header. The cookie is never trusted on the way in: the check compares the header with the session.

The package tests this setup in `app/Config/Security.php`:

```php
public string $csrfProtection = 'session';
public string $headerName     = 'X-XSRF-TOKEN';
public bool $tokenRandomize = true;
```

## Client setup

Client setup is not repeated here. Follow the official guide for your framework at <https://inertiajs.com/client-side-setup> and install the 2.x adapter (`@inertiajs/vue3`, `@inertiajs/react` or `@inertiajs/svelte`).

Minimal Vite notes:

- Build your entry file with [Vite](https://vite.dev) into `public/build` (set `build.outDir` and `build.manifest`).
- Load the built entry and CSS in your root view. Vite's dev server serves them during development.
- Mount the app on `#app`; the client reads the page object from its `data-page` attribute.

## Rendering pages

`service('inertia')` returns the `ResponseFactory`. `render()` builds an `InertiaResponse`; call `toResponse()` with the request and response to get what the controller returns.

```php
<?php

namespace App\Controllers;

class Users extends BaseController
{
    public function index()
    {
        return service('inertia')
            ->render('Users/Index', ['users' => model('UserModel')->findAll()])
            ->toResponse($this->request, $this->response);
    }
}
```

An Inertia request (with an `X-Inertia` header) gets the page object as JSON. Any other request gets the root view. The header selects the format only; it never grants access.

Props must be a scalar, an array, `null` or a prop object from this package. Anything else (an entity, for example) throws `InvalidArgumentException` naming the key, so you state what leaves the server.

## Shared props

Shared props go on every render.

Override `share()` in your own filter that extends `InertiaFilter`, and register that filter instead:

```php
namespace App\Filters;

use CodeIgniter\HTTP\IncomingRequest;
use Ejklock\Ci4Inertia\Filters\InertiaFilter;

class AppInertiaFilter extends InertiaFilter
{
    protected function share(IncomingRequest $request): array
    {
        return [
            ...parent::share($request),
            'appName' => 'My app',
        ];
    }
}
```

Or call `share()` on the service at any point before `render()`:

```php
service('inertia')->share('appName', 'My app');
service('inertia')->share(['appName' => 'My app', 'locale' => 'en']);
```

Props passed to `render()` win over shared props with the same key.

## Validation errors and redirects

`InertiaFilter` shares an `errors` prop: an object of field name to message, empty when there are none. The prop is always sent, even on a partial reload that does not name it.

Redirect with `withInput()` when validation fails. CodeIgniter flashes the validation errors with it, and the next Inertia page receives them as `errors`:

```php
public function store()
{
    if (! $this->validate(['email' => 'required|valid_email'])) {
        return redirect()->to('/users/create')->withInput();
    }

    // save, then:
    return redirect()->to('/users');
}
```

To control the errors yourself, flash an array under the `errors` key instead:

```php
return redirect()->to('/users/create')->with('errors', $this->validator->getErrors());
```

When the session holds an `errors` array, it is the `errors` prop as it is; the errors `withInput()` flashed are ignored, never merged with it.

Redirect to an explicit form URL rather than `redirect()->back()`: Inertia visits do not record CodeIgniter's previous URL, so `back()` can return to the last full page load instead of the form.

After a `PUT`, `PATCH` or `DELETE` the filter changes a `302` redirect into `303`, so the client follows it with a `GET`.

## Asset versioning

The page object carries a version. When the client sends a different `X-Inertia-Version` on a `GET`, `InertiaFilter` answers `409` with an `X-Inertia-Location` header (the current path and query), and the client does a full reload.

The default version is an empty string. Supply a real fingerprint by overriding the config (see [Configuration](#configuration)):

```php
namespace Config;

use Ejklock\Ci4Inertia\Config\Inertia as BaseInertia;

class Inertia extends BaseInertia
{
    public function __construct()
    {
        parent::__construct();

        $this->version = static fn (): string => (string) md5_file(FCPATH . 'build/.vite/manifest.json');
    }
}
```

The config's `version` is the default. To compute the version at runtime, override `version(IncomingRequest $request): string` in an extending filter (the one you register under the `inertia` alias):

```php
namespace App\Filters;

use CodeIgniter\HTTP\IncomingRequest;
use Ejklock\Ci4Inertia\Filters\InertiaFilter as BaseInertiaFilter;

class InertiaFilter extends BaseInertiaFilter
{
    protected function version(IncomingRequest $request): string
    {
        return parent::version($request) . ':' . $request->getHeaderLine('X-Tenant');
    }
}
```

The filter calls the hook at most once per request, and only when the stale-version check of an Inertia `GET` or a render needs the version (a redirect or a non-Inertia response never runs it); its result serves both the check and the page object. A `service('inertia')->version(...)` call inside a controller runs after the check, so the check never compares it: the page object then carries a version that differs from the one the client is compared against, and every Inertia `GET` visit answers `409`, turning each navigation into a full page reload. Set the version in the config or in the filter hook, not in a controller.

## External redirects

`location()` sends the client to a URL with a full page load. On an Inertia request it returns a `409` with `X-Inertia-Location`; otherwise a normal redirect.

```php
return service('inertia')->location('https://example.com/login');
```

Pass a URL your server computed, never one taken from request input.

## Partial reloads

The client can ask for some props only, through `X-Inertia-Partial-Component`, `X-Inertia-Partial-Data` (props to include) and `X-Inertia-Partial-Except` (props to leave out). The server filters the prop keys first and resolves only what is left, so a prop that is not requested is never computed. Partial filtering applies only when the partial component equals the component being rendered.

## Lazy, optional, always and deferred props

Each wraps a callback and resolves it when needed.

```php
use Ejklock\Ci4Inertia\AlwaysProp;
use Ejklock\Ci4Inertia\LazyProp;
use Ejklock\Ci4Inertia\OptionalProp;

$inertia = service('inertia');

return $inertia->render('Dashboard', [
    'stats'    => new LazyProp(fn () => $this->loadStats()),
    'audit'    => new OptionalProp(fn () => $this->loadAudit()),
    'notice'   => new AlwaysProp(fn () => session()->getFlashdata('message')),
    'activity' => $inertia->defer(fn () => $this->loadActivity()),
    'invoices' => $inertia->defer(fn () => $this->loadInvoices(), 'billing'),
    'plan'     => $inertia->defer(fn () => $this->loadPlan(), 'billing'),
])->toResponse($this->request, $this->response);
```

| Prop | Full render | Partial reload |
| --- | --- | --- |
| `LazyProp` | resolved and sent | resolved only if requested |
| `OptionalProp` | left out | resolved only if `X-Inertia-Partial-Data` names it |
| `AlwaysProp` | resolved and sent | always sent, whatever the headers say |
| `DeferProp` (from `defer()`) | left out, listed under `deferredProps` | resolved only if `X-Inertia-Partial-Data` names it |

Wrap a callback in one of these classes: a bare Closure prop is rejected with `InvalidArgumentException`.

Deferred props are grouped. The page object lists them as `deferredProps`, a map of group name to prop keys (here `activity` in `default`, `invoices` and `plan` in `billing`). The client then makes one follow-up request per group. `defer()` takes a callback and a group name (default `default`); an empty group name throws `InvalidArgumentException`.

## Merge props

A merge prop tells the client to combine the new value with the one it already holds instead of replacing it, which suits infinite scroll and chat feeds.

```php
$inertia = service('inertia');

return $inertia->render('Feed', [
    'posts'    => $inertia->merge(fn () => $this->nextPosts()),
    'newest'   => $inertia->merge($latest)->prepend(),
    'settings' => $inertia->deepMerge($settings),
    'users'    => $inertia->merge($users)->matchOn('id'),
])->toResponse($this->request, $this->response);
```

- `merge()` appends. The value is an array, or a closure called only when the prop is resolved.
- `prepend()` prepends; `deepMerge()` merges deeply.
- `matchOn('id', ...)` names the item fields the client uses to replace an existing item instead of adding a duplicate.
- The mode calls `merge()`, `prepend()` and `deepMerge()` can be chained, but the last one called wins. Match fields set with `matchOn()` are kept; a new `matchOn()` replaces them.
- Each call returns a new object; the original is not changed.
- A deferred prop becomes mergeable with the same four methods: `$inertia->defer($callback)->merge()`.

The page object lists the keys under `mergeProps`, `prependProps`, `deepMergeProps` and `matchPropsOn` (entries like `users.id`).

### Resetting a merge

When the client sends `X-Inertia-Reset` with prop names, those keys are dropped from the merge lists but their values are still sent, so the client replaces its current value. Inertia clients send the header when a visit uses the `reset` option.

## History encryption

Ask the client to encrypt the history state of pages, so the back button cannot show their data after a user logs out.

Turn it on for every page with the `encryptHistory` config setting, or per request:

```php
service('inertia')->encryptHistory();      // on
service('inertia')->encryptHistory(false); // off
```

It affects the renders that follow in the same request. The page object then carries `encryptHistory: true`.

`clearHistory()` asks the client to discard its encrypted history once, for example on logout:

```php
service('inertia')->clearHistory();

return redirect()->to('/login');
```

The next render, in this request or after the redirect, carries `clearHistory: true`, and that request consumes the flag. The flag is kept in the session, so the session must be available.

## Testing

Use `InertiaTestTrait` in a CodeIgniter test case to assert on the page a response carried, from the JSON form or from the HTML `data-page` attribute.

```php
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Ejklock\Ci4Inertia\Testing\AssertableInertia;
use Ejklock\Ci4Inertia\Testing\InertiaTestTrait;

final class UsersTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use InertiaTestTrait;

    public function testListsUsers(): void
    {
        $result = $this->withHeaders($this->inertiaHeaders((config('Inertia')->version)()))->get('/users');

        $this->assertInertia($result, static function (AssertableInertia $page): void {
            $page->component('Users/Index')
                ->url('/users')
                ->has('users', 2)
                ->where('users.0.name', 'Ada')
                ->where('users.1.age', static fn (int $age): bool => $age > 18)
                ->missing('secret');
        });
    }
}
```

- `assertInertia(TestResponse $result, ?Closure $callback = null): AssertableInertia` fails if the response has no page.
- `inertiaHeaders(string $version = '', array $extra = []): array` builds the Inertia request headers. Pass the app's current asset version, the one your filter's `version()` returns (by default `(config('Inertia')->version)()`): a version that differs from it gets a `409` from the filter instead of the page.
- `AssertableInertia` methods: `component()`, `url()`, `version()`, `has($path, ?$count)`, `missing()`, `where($path, $expected)` (a value or a closure returning bool), `count()`, `prop()` and `page()`. Paths use dots (`users.0.name`); a prop whose value is `null` counts as present.

## Configuration

Override the defaults with your own `app/Config/Inertia.php` that extends `Ejklock\Ci4Inertia\Config\Inertia` (see the example under [Asset versioning](#asset-versioning)).

| Setting | Type | Default | Meaning |
| --- | --- | --- | --- |
| `rootView` | `string` | `'app'` | View rendered for a non-Inertia visit. |
| `encryptHistory` | `bool` | `false` | Ask the client to encrypt history state on every page. |
| `version` | `Closure(): string` | returns `''` | Produces the asset version. |

To replace the `inertia` service itself, define an `inertia()` method in your own `Config\Services`.

## Not supported yet

This package supports Inertia protocol v2. These v3 features are not supported yet:

- once props
- scroll props
- prefetch metadata
- nested merge paths

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) to set up, test and send a change, and [SECURITY.md](SECURITY.md) to report a vulnerability privately. Everyone taking part follows the [Code of Conduct](CODE_OF_CONDUCT.md).

## License

MIT. See [LICENSE](LICENSE).
