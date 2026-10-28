# ci4-inertia

[![CI](https://github.com/ejklock/ci4-inertia/actions/workflows/ci.yml/badge.svg)](https://github.com/ejklock/ci4-inertia/actions/workflows/ci.yml)
[![Packagist version](https://img.shields.io/packagist/v/ejklock/ci4-inertia.svg)](https://packagist.org/packages/ejklock/ci4-inertia)
[![PHP version](https://img.shields.io/packagist/php-v/ejklock/ci4-inertia.svg)](https://packagist.org/packages/ejklock/ci4-inertia)
[![License](https://img.shields.io/packagist/l/ejklock/ci4-inertia.svg)](LICENSE)

Server-side [Inertia.js](https://inertiajs.com) adapter for CodeIgniter 4. Build a single-page app with Vue, React or Svelte while keeping CodeIgniter routing, controllers, sessions and validation. It ports [inertia-laravel](https://github.com/inertiajs/inertia-laravel) 3.5 to CodeIgniter filters, config, services and spark commands.

New here? Start with the [Quickstart](#quickstart). Before you ship, read [Security](#security): the application owns several of those checks.

Release notes are in [CHANGELOG.md](CHANGELOG.md). Moving from 2.x or from the 0.x line? See [UPGRADING.md](UPGRADING.md).

## Contents

- [Requirements](#requirements) and [Installation](#installation)
- [Quickstart](#quickstart)
- [Root view](#root-view), [Filters](#filters), [CSRF](#csrf), [Client setup](#client-setup)
- [Rendering pages](#rendering-pages), [Helpers](#helpers), [Shared props](#shared-props)
- [Validation errors and redirects](#validation-errors-and-redirects), [Flash data](#flash-data), [Asset versioning](#asset-versioning), [External redirects](#external-redirects)
- Props: [Partial reloads](#partial-reloads), [Optional, always and deferred props](#optional-always-and-deferred-props), [Once props](#once-props), [Merge props](#merge-props), [Scroll props](#scroll-props), [Prop providers](#prop-providers)
- [History encryption](#history-encryption), [Fragments](#preserving-the-url-fragment), [Big integers](#big-integers)
- [Server-side rendering](#server-side-rendering), [Exception pages](#exception-pages)
- [Testing](#testing), [Configuration](#configuration), [Not supported](#not-supported)
- [Security](#security)

## Requirements

| Package | Version |
| --- | --- |
| PHP | ^8.2 (tested on 8.2, 8.3, 8.4 and 8.5) |
| codeigniter4/framework | ^4.7 (lowest tested: 4.7.4) |
| Inertia protocol | v3 (client `@inertiajs/*` 3.x) |

## Installation

```bash
composer require ejklock/ci4-inertia
```

The package registers the `inertia` service and the three `inertia:*` spark commands through CodeIgniter's package discovery. The `inertia` helper is not loaded by discovery: the root view loads it, and `helper('inertia')` finds the file by namespace. Nothing else is published into your app.

## Quickstart

Follow these steps in order, from a fresh CodeIgniter 4.7 app, to render a first Vue 3 page through Vite. Each step names the file it writes. The sections after it are the reference.

1. Install the package.

   ```bash
   composer require ejklock/ci4-inertia
   ```

2. Install the client. A fresh CodeIgniter app has no `package.json`; run `npm init -y` first, then set `"type": "module"` and add the `dev` and `build` scripts in the file it creates.

   ```bash
   npm init -y
   npm install @inertiajs/vue3 vue
   npm install -D vite @vitejs/plugin-vue
   ```

   The 3.x client reads the first page from a `<script data-page="app" type="application/json">` tag, not from a `data-page` attribute. [`inertia_app()`](#root-view) prints that tag. Do not install a 2.x client (`@inertiajs/*@^2`): it reads the page from an attribute and finds nothing.

   `package.json` (the keys to add):

   ```json
   {
     "type": "module",
     "scripts": {
       "dev": "vite",
       "build": "vite build"
     }
   }
   ```

3. Write `vite.config.js`. `php spark serve` listens on `http://localhost:8080`, so that origin is allowed to load assets from the Vite dev server. If `spark serve` or Vite run on another port, or you open the page as `127.0.0.1`, change `server.cors.origin` and the root view URLs in step 6 to match. The `build` keys make `npm run build` write the files and the manifest that [Client setup](#client-setup) loads in production; `publicDir: false` stops Vite from copying `public/` (with `index.php`) into `public/build`.

   ```js
   import { defineConfig } from 'vite'
   import vue from '@vitejs/plugin-vue'

   export default defineConfig({
     plugins: [vue()],
     server: {
       cors: {
         origin: 'http://localhost:8080',
       },
     },
     publicDir: false,
     build: {
       outDir: 'public/build',
       manifest: true,
       rollupOptions: {
         input: 'resources/js/app.js',
       },
     },
   })
   ```

4. Write `resources/js/app.js`. The `resolve` callback maps the component name a controller passes to a file; the client mounts the app on `#app` by itself.

   ```js
   import { createInertiaApp } from '@inertiajs/vue3'

   createInertiaApp({
     resolve: name => {
       const pages = import.meta.glob('./pages/**/*.vue', { eager: true })
       return pages[`./pages/${name}.vue`]
     },
   })
   ```

   The `@inertiajs/vite` plugin is an alternative that resolves pages for you; see the [Inertia client setup guide](https://inertiajs.com/client-side-setup). This guide uses the explicit `resolve`.

5. Write the page component `resources/js/pages/Home.vue`. The name `Home` is the component name the controller passes to `Inertia::render()`. The directory is `Config\Inertia::$pagePaths`' default, `resources/js/pages`.

   ```vue
   <script setup>
   defineProps({ greeting: String })
   </script>

   <template>
     <h1>{{ greeting }}</h1>
   </template>
   ```

6. Write the root view `app/Views/app.php`. It receives the page object in `$page` and prints it with `inertia_app($page)`. In development it loads the Vite dev server; loading the built files in production is covered in [Client setup](#client-setup).

   ```php
   <!DOCTYPE html>
   <html lang="en">
   <head>
       <meta charset="utf-8">
       <meta name="viewport" content="width=device-width, initial-scale=1">
       <title>My app</title>
       <?= inertia_head($page) ?>
       <script type="module" src="http://localhost:5173/@vite/client"></script>
       <script type="module" src="http://localhost:5173/resources/js/app.js"></script>
   </head>
   <body>
   <?= inertia_app($page) ?>
   </body>
   </html>
   ```

7. Register the filter in `app/Config/Filters.php` (see [Filters](#filters)).

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

8. Confirm the route in `app/Config/Routes.php`. The starter already has it, so add the line only if it is missing.

   ```php
   $routes->get('/', 'Home::index');
   ```

9. Write the controller `app/Controllers/Home.php` (see [Rendering pages](#rendering-pages)).

   ```php
   <?php

   namespace App\Controllers;

   use Ejklock\Ci4Inertia\Inertia;

   class Home extends BaseController
   {
       public function index()
       {
           return Inertia::render('Home', ['greeting' => 'Hello from Inertia'])
               ->toResponse($this->request, $this->response);
       }
   }
   ```

10. Run both servers in two terminals, then open <http://localhost:8080>.

    ```bash
    php spark serve
    npm run dev
    ```

    The page shows the greeting. Visits made with `<Link>` or `router` from here on get JSON.

Next: [Client setup](#client-setup) for production builds, [Rendering pages](#rendering-pages) for props, and [Filters](#filters) for CSRF and filter order.

## Root view

A first visit (or a hard reload) returns an HTML page. Create the root view `app/Views/app.php`. It receives the page object as an array in `$page`, plus anything added with `withViewData()`. Print it with `inertia_app($page)`, and the server-rendered head with `inertia_head($page)`:

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My app</title>
    <?= inertia_head($page) ?>
    <!-- load your built assets here -->
</head>
<body>
<?= inertia_app($page) ?>
</body>
</html>
```

- `inertia_app(array $page, string $id = 'app'): string` returns the server-side render of the page when the SSR server gave one (see [Server-side rendering](#server-side-rendering)). Otherwise it returns the page as `<script data-page="app" type="application/json">…</script><div id="app"></div>`. The JSON is encoded with `JSON_HEX_TAG`, so a prop value cannot close the tag. `$id` is the id of the script's `data-page` and of the mount element; it must match the `id` option of `createInertiaApp()`. It throws `JsonException` for a value that cannot be encoded.
- `inertia_head(array $page, string $fallback = ''): string` returns the head the SSR server rendered, or `$fallback` as given when the page was not rendered on the server. Print it inside `<head>`.
- The view is named `app` by default. Change it with the `rootView` setting (see [Configuration](#configuration)), `service('inertia')->setRootView()` for the renders that follow, or `rootView()` on one response.
- Do not print `$page` yourself, and do not pass it to `esc()`: `esc()` escapes an array element by element, so the page prints as `Array`. See [Security](#security) for the one safe manual form.

The helpers load with the root view. To call `inertia()` or `inertia_location()` from a controller, load them first with `helper('inertia')`.

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
- on an Inertia request only, turns a `302` into a `303` after a `PUT`, `PATCH` or `DELETE`, so the browser follows the redirect with a `GET`. Only a `302` is rewritten; another redirect status is sent as it is;
- answers an empty `200` on an Inertia request with a redirect back (see [Validation errors and redirects](#validation-errors-and-redirects));
- turns a redirect to a URL with a `#fragment` into an empty `409` with `X-Inertia-Redirect`, so the client navigates there itself, except for a prefetch request;
- flashes the Inertia flash data again on a redirect, so the page the redirect leads to carries it (see [Flash data](#flash-data));
- records an Inertia `GET` as CodeIgniter's previous URL when `storePreviousUrl` is on.

The package ships five filters:

| Filter | What it does |
| --- | --- |
| `InertiaFilter` | All of the above. Extend it to share props, to compute the version, or to change `onEmptyResponse()` and `onRedirectWithFragment()`. |
| `InertiaCsrfFilter` | CodeIgniter's CSRF filter with an Inertia-aware failure answer. See [CSRF](#csrf). |
| `XsrfTokenCookieFilter` | Writes the CSRF token into the `XSRF-TOKEN` cookie. See [CSRF](#csrf). |
| `EncryptHistoryFilter` | Calls `encryptHistory()` before the controller, so the routes behind it ask the client to encrypt history state without turning on the `encryptHistory` setting for the whole app. |
| `EnsureGetOnRedirectFilter` | Turns a `302` into a `303` after an Inertia `PUT`, `PATCH` or `DELETE`. For a route group that needs this without the rest of `InertiaFilter`. |

`InertiaFilter` extension points, all `protected`: `share(IncomingRequest $request): array`, `version(IncomingRequest $request): string`, `onEmptyResponse()`, `onRedirectWithFragment()`, and the properties `$withAllErrors` (send every message of a field as a list instead of the first one) and `$withoutSsr` (a list of path patterns left out of server rendering, `*` matches any characters).

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
- `XsrfTokenCookieFilter` writes the CSRF token into a script-readable `XSRF-TOKEN` cookie on every response with a status below 400. The 3.x client no longer ships with or requires axios: its built-in HTTP client checks for an `XSRF-TOKEN` cookie and sends it back in an `X-XSRF-TOKEN` header on each request it makes (the [CSRF page of the Inertia docs](https://inertiajs.com/docs/v3/security/csrf-protection) describes this; the client sends it for same-origin URLs only). The client's cookie and header names default to `XSRF-TOKEN` and `X-XSRF-TOKEN` and can be changed through its `http` option. The filter always writes the cookie as `XSRF-TOKEN`, so keep that cookie name on the client and keep `Config\Security::$headerName` equal to the client's header name. The cookie is never trusted on the way in: the check compares the header with the session. Its `path` and `secure` flag come from `Config\Cookie`, and it is `SameSite=Lax`.

The package tests this setup in `app/Config/Security.php`:

```php
public string $csrfProtection = 'session';
public string $headerName     = 'X-XSRF-TOKEN';
public bool $tokenRandomize = true;
```

## Client setup

Client setup is not repeated here. Follow the official guide for your framework at <https://inertiajs.com/client-side-setup> and install the 3.x adapter (`@inertiajs/vue3`, `@inertiajs/react` or `@inertiajs/svelte`). Do not pin a 2.x range.

The adapter reads the first page from `<script data-page="app" type="application/json">`, which `inertia_app()` prints, and mounts on the element with the same id. To use another id, pass it to both `inertia_app($page, 'my-app')` and `createInertiaApp({ id: 'my-app' })`.

Production: run `npm run build` (the Quickstart's Vite config writes `public/build` with a manifest), then load the built entry and its CSS in the root view:

```php
<?php
$manifest = json_decode(file_get_contents(FCPATH . 'build/.vite/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$entry    = $manifest['resources/js/app.js'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My app</title>
    <?= inertia_head($page) ?>
<?php foreach ($entry['css'] ?? [] as $css): ?>
    <link rel="stylesheet" href="<?= esc(base_url('build/' . $css), 'attr') ?>">
<?php endforeach ?>
    <script type="module" src="<?= esc(base_url('build/' . $entry['file']), 'attr') ?>"></script>
</head>
<body>
<?= inertia_app($page) ?>
</body>
</html>
```

Never leave a `public/hot` file on a production server (see [Security](#security)).

## Rendering pages

`service('inertia')` returns the `ResponseFactory`. `Inertia` is a static shortcut for it with the same methods: `Inertia::render()` is `service('inertia')->render()`, and `Inertia::defer()`, `merge()`, `share()` and the rest follow. The other examples in this document use `service('inertia')`.

```php
use Ejklock\Ci4Inertia\Inertia;

return Inertia::render('Users/Index', ['stats' => Inertia::defer(fn () => $this->stats())])
    ->toResponse($this->request, $this->response);
```

`render()` builds an `InertiaResponse`; call `toResponse()` with the request and response to get what the controller returns.

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

The component is a string, a string-backed enum (read by its value) or a pure enum (read by its name). `transformComponentUsing(?Closure $transformer)` rewrites the component of every render that follows before the enum mapping; a `null` result keeps the original, and `null` stops transforming. When `ensurePagesExist` is on, `render()` throws `ComponentNotFoundException` if no file under `pagePaths` with one of `pageExtensions` matches.

What a prop may be: a scalar, an array, `null`, a closure or invokable object (called only when the prop is sent), a prop object from this package, or an object. A `JsonSerializable` object is sent as its `jsonSerialize()` value; any other object is encoded by `json_encode` (its public properties). A CodeIgniter `Entity` is a `JsonSerializable`: it sends the attributes its `toArray()` holds, so pass only what the page needs.

`InertiaResponse` methods, chained before `toResponse()`:

| Method | What it does |
| --- | --- |
| `with($key, $value = null)` | Adds props: a map, one name and value, or a `ProvidesInertiaProperties`. A later key wins over a render prop of the same name. |
| `withViewData($key, $value = null)` | Adds variables for the root view only. The page object is never affected, and a variable named `page` is replaced by the page object. |
| `rootView(string $view)` | The root view of this response. |
| `flash($key, $value = null)` | Flashes data for this page (see [Flash data](#flash-data)). |
| `preserveBigIntegers(bool $preserve = true)` | See [Big integers](#big-integers). |
| `toResponse($request, $response)` | Builds the page and returns JSON or the root view. |

`ResponseFactory` setters that affect the renders that follow: `setRootView(string)`, `resolveUrlUsing(?Closure)` (computes the page object's `url` from the request; `null` restores the request path and query), `encryptHistory(bool $encrypt = true)` and `version()`.

## Helpers

Four global functions, loaded with `helper('inertia')`:

| Helper | What it does |
| --- | --- |
| `inertia_app($page, $id = 'app')` | Prints the page in the root view. See [Root view](#root-view). |
| `inertia_head($page, $fallback = '')` | Prints the SSR head in the root view. |
| `inertia($component = null, $props = [])` | With no component, returns the `ResponseFactory`; with one, returns `render($component, $props)`. |
| `inertia_location($url)` | Same as `service('inertia')->location($url)`. |

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
service('inertia')->share('auth.user', fn () => $this->currentUser());
```

A dot path in a single name nests the value: `share('auth.user', $user)`, or a render prop `'auth.user' => $user`, reaches the client as `{"auth": {"user": ...}}`, not as a flat `"auth.user"` key. This changed in 3.0.0; see [Step 10 of UPGRADING.md](UPGRADING.md#step-10-read-dotted-prop-keys-as-nested-props) for the move from 0.2.x. `share()` also takes a [`ProvidesInertiaProperties`](#prop-providers). Related methods: `getShared(?string $key = null, mixed $default = null)` reads one key (or every shared prop), and `flushShared()` drops them all. `shareOnce($key, $callback)` shares a [once prop](#once-props).

Props passed to `render()` win over shared props with the same key. With `exposeSharedPropKeys` on (the default), the page lists the top-level keys of what `share()` registered under `sharedProps`, so the client can carry them over during instant visits.

## Validation errors and redirects

`InertiaFilter` shares an `errors` prop: an object of field name to message, empty when there are none. The prop is always sent, even on a partial reload that does not name it. A request that sends `X-Inertia-Error-Bag` gets the errors scoped under that name.

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

`service('inertia')->back(int $status = 302, array $headers = [], false|string $fallback = false)` redirects to the previous location: the `Referer` header, else the URL CodeIgniter stored for the last page when the request carries a session, else `$fallback`, else `/`. Only a `Referer` or a stored URL that is on the site is followed (see [Security](#security)); `$fallback` is your own value and is used as given. With `storePreviousUrl` on (the default), `InertiaFilter` records each Inertia `GET` that renders a page as the previous URL, so `back()` returns to the page the visitor is on, and not to the last full page load.

An empty `200` on an Inertia request is answered with a redirect to the stored previous URL, else an on-site `Referer`, else the site root, with the same on-site rule. Override `onEmptyResponse()` in an extending filter to answer differently.

After a mutation, prefer an explicit `redirect()->to('/users')` over `back()`.

After a `PUT`, `PATCH` or `DELETE` on an Inertia request the filter changes a `302` redirect into `303`, so the client follows it with a `GET`.

## Flash data

Flash data is carried once under the page object's `flash` key. The client does not keep it in its history state, so use it for a toast, not for data the page needs after a reload.

```php
service('inertia')->flash('toast', 'Saved');
service('inertia')->flash(['toast' => 'Saved', 'level' => 'success']);

return redirect()->to('/users');
```

- `flash($key, $value = null)` takes a key, a string-backed or pure enum, or a map. New data is merged over what is flashed, so a repeated key takes the latest value. It survives a redirect and is dropped once a page has carried it.
- `getFlashed()` reads the flash data without removing it; `pullFlashed()` reads and removes it. Both return an empty array, and start no session, when the request carries none.
- `InertiaResponse::flash()` flashes for one page.

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

The filter calls the hook at most once per request, and only when the stale-version check of an Inertia `GET` or a render needs the version (a `POST` or a non-Inertia request that does not render never runs it); its result serves both the check and the page object. A `service('inertia')->version(callable|string $version)` call inside a controller runs after the check, so the check never compares it: the page object then carries a version that differs from the one the client is compared against, and every Inertia `GET` visit answers `409`, turning each navigation into a full page reload. Set the version in the config or in the filter hook, not in a controller. `getVersion()` reads the version; the resolver runs at most once per service instance.

## External redirects

`location()` sends the client to a URL with a full page load. On an Inertia request it returns a `409` with `X-Inertia-Location`; otherwise a normal redirect. It takes a string or a `RedirectResponse`.

```php
return service('inertia')->location('https://example.com/login');
```

The argument is used verbatim. Pass a URL your server computed, never one taken from request input (see [Security](#security)).

## Partial reloads

The client can ask for some props only, through `X-Inertia-Partial-Component`, `X-Inertia-Partial-Data` (props to include) and `X-Inertia-Partial-Except` (props to leave out). The server filters the prop keys first and resolves only what is left, so a prop that is not requested is never computed. Partial filtering applies only when the partial component equals the component being rendered. A dot path (`auth.user`) reaches a nested prop.

## Optional, always and deferred props

Each wraps a callback and resolves it when needed.

```php
use Ejklock\Ci4Inertia\AlwaysProp;
use Ejklock\Ci4Inertia\OptionalProp;

$inertia = service('inertia');

return $inertia->render('Dashboard', [
    'audit'    => new OptionalProp(fn () => $this->loadAudit()),
    'notice'   => new AlwaysProp(fn () => session()->getFlashdata('message')),
    'stats'    => fn () => $this->loadStats(),
    'activity' => $inertia->defer(fn () => $this->loadActivity()),
    'invoices' => $inertia->defer(fn () => $this->loadInvoices(), 'billing'),
    'plan'     => $inertia->defer(fn () => $this->loadPlan(), 'billing'),
])->toResponse($this->request, $this->response);
```

| Prop | Full render | Partial reload |
| --- | --- | --- |
| A closure or invokable object | resolved and sent | resolved only if requested |
| `OptionalProp` (`optional()`) | left out | resolved only if `X-Inertia-Partial-Data` names it |
| `AlwaysProp` (`always()`) | resolved and sent | always sent, whatever the headers say |
| `DeferProp` (`defer()`) | left out, listed under `deferredProps` | resolved only if `X-Inertia-Partial-Data` names it |

`optional($callback)` and `always($value)` are the `ResponseFactory` forms. A string or an array that merely names a function stays as it is; only a closure or an invokable object is called.

Deferred props are grouped. The page object lists them as `deferredProps`, a map of group name to prop keys (here `activity` in `default`, `invoices` and `plan` in `billing`). The client then makes one follow-up request per group. `defer(callable $callback, string $group = 'default', bool $rescue = false)` takes a callback, a group name and a rescue flag; an empty group name throws `InvalidArgumentException`. With `$rescue` on, a throw from the callback is logged and the prop is left out of the response and listed under `rescuedProps`, instead of failing the request.

## Once props

A once prop is one the client keeps after its first load. Later Inertia visits send the keys they hold in `X-Inertia-Except-Once-Props`, and the server does not send those again. The page lists them under `onceProps`.

```php
$inertia = service('inertia');

$inertia->share('countries', fn () => $this->countries());      // sent on every visit
$inertia->shareOnce('plans', fn () => $this->plans())->until(3600);

return $inertia->render('Signup', [
    'rates' => $inertia->once(fn () => $this->rates())->as('rates:v2')->fresh(false),
])->toResponse($this->request, $this->response);
```

- `once(callable $callback)` makes a `OnceProp`; `shareOnce(string $key, callable $callback)` shares one under the key and returns it. A dot path nests the value.
- Modifiers: `once(bool $value = true, ?string $as = null, $until = null)`, `as($key)` (the key the client tracks it under instead of its path), `until($seconds | DateInterval | DateTimeInterface)` (when the client's copy expires), `fresh(bool $value = true)` (send it even when the client holds it).
- `OptionalProp`, `DeferProp` and `MergeProp` take the same modifiers: `$inertia->optional($callback)->once()`.
- Behavior at this release: `OnceProp` is mutable, so the modifiers change the instance and return it, which lets a prop handed to `share()` still be configured. The other props return a modified copy.

## Merge props

A merge prop tells the client to combine the new value with the one it already holds instead of replacing it, which suits infinite scroll and chat feeds.

```php
$inertia = service('inertia');

return $inertia->render('Feed', [
    'posts'    => $inertia->merge(fn () => $this->nextPosts()),
    'newest'   => $inertia->merge($latest)->prepend(),
    'settings' => $inertia->deepMerge($settings),
    'users'    => $inertia->merge($users)->matchOn('id'),
    'feed'     => $inertia->merge($feed)->append('items', 'id')->prepend('pinned'),
])->toResponse($this->request, $this->response);
```

- `merge()` appends. The value is an array, or a closure called only when the prop is resolved.
- `prepend()` prepends; `deepMerge()` merges deeply.
- `append($path = true, ?string $matchOn = null)` and `prepend($path = true, ?string $matchOn = null)` take a nested path (a string, or an array of paths) and the item field that matches there, so one prop can append at one path and prepend at another.
- `matchOn('id', ...)` names the item fields the client uses to replace an existing item instead of adding a duplicate.
- The mode calls `merge()`, `prepend()` and `deepMerge()` can be chained, but the last one called wins. Match fields set with `matchOn()` are kept; a new `matchOn()` replaces them.
- Each call returns a new object; the original is not changed.
- A deferred prop becomes mergeable with `merge()` or `deepMerge()`: `$inertia->defer($callback)->merge()`. `append()`, `prepend()` and `matchOn()` only shape a merge that is on.

The page object lists the keys under `mergeProps`, `prependProps`, `deepMergeProps` and `matchPropsOn` (entries like `users.id`).

### Resetting a merge

When the client sends `X-Inertia-Reset` with prop names, those keys are dropped from the merge lists but their values are still sent, so the client replaces its current value. Inertia clients send the header when a visit uses the `reset` option.

## Scroll props

A scroll prop is a paginated prop the client scrolls through, merging each page it loads into the value it holds.

```php
$users = model('UserModel')->paginate(20);

return service('inertia')->render('Users/Index', [
    'users' => service('inertia')->scroll(['data' => $users]),
])->toResponse($this->request, $this->response);
```

- `scroll(mixed $value, string $wrapper = 'data', callable|ProvidesScrollMetadata|null $metadata = null)` makes a `ScrollProp`. `$wrapper` is the key of the paginated items inside the value. The value may be a closure, called only when the prop is sent.
- The page object lists the prop's position under `scrollProps`: page name, previous, next and current page.
- Without `$metadata` the position is read from the shared pager's `default` group once the value has resolved, which is where `$model->paginate()` stores it. For another group pass `ScrollMetadata::fromPager($pager, 'users')`. It throws `InvalidArgumentException` when the group holds no pagination.
- `$metadata` can also be a `ProvidesScrollMetadata` (`getPageName()`, `getPreviousPage()`, `getNextPage()`, `getCurrentPage()`) or a callable that builds one from the resolved value. `new ScrollMetadata($pageName, $previousPage, $nextPage, $currentPage)` is the plain implementation.
- The merge direction follows the client's `X-Inertia-Infinite-Scroll-Merge-Intent` header. The prop is immutable and can be deferred with `defer()`.

## Prop providers

A class that implements `ProvidesInertiaProperties` supplies several props at once. Pass it as the props of `render()`, as an item of the props array, or to `share()`:

```php
use Ejklock\Ci4Inertia\ProvidesInertiaProperties;
use Ejklock\Ci4Inertia\RenderContext;

final class AuthProps implements ProvidesInertiaProperties
{
    public function toInertiaProperties(RenderContext $context): iterable
    {
        yield 'user' => session('user_name');
        yield 'canEdit' => $context->component === 'Users/Edit';
    }
}

return service('inertia')->render('Users/Edit', new AuthProps());
```

`RenderContext` carries the `component` and the `request`.

A class that implements `ProvidesInertiaProperty` converts into the value of one prop. Its `toInertiaProperty(PropertyContext $prop): mixed` receives the prop's dot path in `$prop->key`, its sibling props in `$prop->props` and the `request`.

## History encryption

Ask the client to encrypt the history state of pages, so the back button cannot show their data after a user logs out.

Turn it on for every page with the `encryptHistory` config setting (default `false`), for a group of routes with `EncryptHistoryFilter`, or per request:

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

## Preserving the URL fragment

`preserveFragment()` asks the client to keep the URL fragment on the next visit. The next render, in this request or after a redirect, carries `preserveFragment: true`, and that request consumes the flag. The flag is kept in the session.

```php
service('inertia')->preserveFragment();

return redirect()->to('/users#details');
```

## Big integers

JavaScript loses integers beyond 2^53 - 1. With the `preserveBigIntegers` setting on (default `false`), every page sends such integers in its props and flash data as `{"$bigint": "<digits>"}` markers and carries `preserveBigIntegers: true`, so the client can revive them. One response opts in or out with `->preserveBigIntegers(bool $preserve = true)`.

## Server-side rendering

With SSR, the first visit is rendered by a Node (or Bun) server and `inertia_app()` prints its output. Build the SSR bundle as the [Inertia SSR guide](https://inertiajs.com/server-side-rendering) describes, then run the server from spark:

```bash
php spark inertia:start-ssr [--runtime <runtime>]
php spark inertia:check-ssr
php spark inertia:stop-ssr [--graceful]
```

| Command | What it does |
| --- | --- |
| `inertia:start-ssr` | Runs the bundle with `ssrRuntime` (or `--runtime`) and relays its output until it exits. Fails when `ssrEnabled` is off or no bundle is found; stops a running server first. |
| `inertia:check-ssr` | Exit `0` when the server answers `/health` with a 2xx status, else exit `1`. |
| `inertia:stop-ssr` | Asks the server to shut down. `--graceful` exits `0` when it is not running. |

The bundle is the first existing file among `ssrBundle`, `bootstrap/ssr/ssr.js`, `bootstrap/ssr/app.js`, `bootstrap/ssr/ssr.mjs`, `bootstrap/ssr/app.mjs` (under the app root), `js/ssr.js` and `js/app.js` (under `public/`).

How a page is dispatched: when `ssrEnabled` is on, the page is posted as JSON to `ssrUrl/render`. A page is rendered on the server only when a bundle exists (`ssrEnsureBundleExists`, default on; skipped in hot mode). Waiting is limited by `ssrTimeout` (default 30 seconds; connecting is always limited to 10). When the render fails or the server is unreachable, the `SsrRenderFailed` event fires through `Events::on(SsrRenderFailed::class, …)` and the page falls back to client rendering; with `ssrThrowOnError` on, the gateway throws `SsrException` instead.

In development, a `public/hot` file (`ssrHotFile`) whose contents are the dev server's URL switches the gateway to hot mode: the page is posted to `<url>/__inertia_ssr`. `ssrHotUrl` pins that URL and wins over the contents of the file while the file exists. The gateway uses a hot URL only when it is an http or https URL with a host; otherwise it reports a failure.

Control SSR per request:

| Call | What it does |
| --- | --- |
| `Inertia::disableSsr(bool\|Closure $condition = true)` | Turns SSR off, or only while the closure returns true. A set condition replaces `ssrEnabled`. |
| `Inertia::withoutSsr(array\|string $paths)` | Leaves paths (patterns where `*` matches any characters) out of SSR. `InertiaFilter::$withoutSsr` does the same for every request the filter handles. |
| `Inertia::configureSsrRequestUsing(?Closure $callback)` | The callback receives the `CURLRequest` and may return a replacement, for a header or an option. `null` clears it. |

These throw `LogicException` when a replaced gateway does not support them. The gateway behind them is the `inertiaSsrGateway` service; `inertiaSsrState` holds the page being rendered.

See [Security](#security) before you expose or deploy any of this.

## Exception pages

Render an Inertia error page for an uncaught exception. Return the package's handler from `app/Config/Exceptions.php`:

```php
use CodeIgniter\Debug\ExceptionHandlerInterface;
use Ejklock\Ci4Inertia\InertiaExceptionHandler;

public function handler(int $statusCode, Throwable $exception): ExceptionHandlerInterface
{
    return new InertiaExceptionHandler($this);
}
```

Register the callback once at boot, for example in `app/Config/Events.php`:

```php
use CodeIgniter\Events\Events;
use Ejklock\Ci4Inertia\ExceptionResponse;
use Ejklock\Ci4Inertia\Inertia;

Events::on('pre_system', static function (): void {
    Inertia::handleExceptionsUsing(static function (ExceptionResponse $response) {
        if (in_array($response->statusCode(), [403, 404, 500, 503], true)) {
            return $response->render('Error', ['status' => $response->statusCode()])->withSharedData();
        }

        return null;
    });
});
```

The callback receives an `ExceptionResponse`, which holds the `exception`, the `request` and the `response`. It returns that object, a `ResponseInterface`, or `null` to leave the answer to CodeIgniter's own handler. Any other return throws `LogicException`. With no callback, and for a CLI request, CodeIgniter's handler runs. `Inertia::handleExceptionsUsing()` is on the static class only, not on `ResponseFactory`.

`ExceptionResponse` methods: `render(string $component, array $props = [])`, `withSharedData()` (add the filter's `share()` props, `errors` included), `usingFilter(string $filterClass)` (the `InertiaFilter` whose version and shared props the page uses, instead of the first `InertiaFilter` among the `Config\Filters` aliases), `rootView(string $view)` and `statusCode()`. The page carries the exception's status and `Vary: X-Inertia`.

The callback gets an `ExceptionResponse` whose `exception` holds the `Throwable`. Never put `getMessage()`, `getTrace()` or `getFile()` in the props in production (see [Security](#security)).

## Testing

Use `InertiaTestTrait` in a CodeIgniter test case to assert on the page a response carried, from the JSON form or from the HTML `<script data-page type="application/json">` element.

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

`InertiaTestTrait` methods:

- `assertInertia(TestResponse $result, ?Closure $callback = null): AssertableInertia` fails if the response has no page.
- `inertiaHeaders(string $version = '', array $extra = []): array` builds the Inertia request headers. Pass the app's current asset version, the one your filter's `version()` returns (by default `(config('Inertia')->version)()`): a version that differs from it gets a `409` from the filter instead of the page.
- `inertiaPage(TestResponse $result): array` is the decoded page object; `inertiaProp(TestResponse $result, ?string $key = null): mixed` is one prop (a dot path), or every prop.
- `assertInertiaFlash(TestResponse $result, string $key, mixed $expected = null)` and `assertInertiaFlashMissing(TestResponse $result, string $key)` check the Inertia flash data the session holds after a request that redirected.

`AssertableInertia` methods: `component($expected, ?bool $shouldExist = null)`, `url()`, `version()`, `has($path, ?$count)`, `missing()`, `hasAll(...$paths)`, `missingAll(...$paths)`, `where($path, $expected)` (a value or a closure returning bool), `count($path, $expected)`, `hasFlash($key, $expected)`, `missingFlash($key)`, `prop($path)` and `page()`. Paths use dots (`users.0.name`); a prop whose value is `null` counts as present. `component()` also asserts that the page file exists when `ensurePagesExistInTests` is on (default); pass `false` as `$shouldExist` to skip it for one call.

To follow a page, the test case needs `FeatureTestTrait` too:

- `reload(?Closure $callback = null, $only = null, $except = null)` requests the page again with the version header (and the partial headers when `$only` or `$except` is given), asserts the same component, url and version, and hands the reloaded page to the callback.
- `reloadOnly($only, ?Closure $callback = null)` and `reloadExcept($except, ?Closure $callback = null)` assert that each named prop came back, or is gone.
- `loadDeferredProps($groupsOrCallback, ?Closure $callback = null)` loads the deferred groups (all of them for a closure alone, or the named ones) with one partial reload. It fails when the groups hold no key.

## Configuration

Override the defaults with your own `app/Config/Inertia.php` that extends `Ejklock\Ci4Inertia\Config\Inertia` (see the example under [Asset versioning](#asset-versioning)).

| Setting | Type | Default | Meaning |
| --- | --- | --- | --- |
| `rootView` | `string` | `'app'` | View rendered for a non-Inertia visit. It receives `$page`. |
| `encryptHistory` | `bool` | `false` | Ask the client to encrypt history state on every page. |
| `preserveBigIntegers` | `bool` | `false` | Send integers beyond 2^53 - 1 as `$bigint` markers. |
| `storePreviousUrl` | `bool` | `true` | Record an Inertia `GET` as CodeIgniter's previous URL, so `back()` returns to the page the visitor is on. |
| `exposeSharedPropKeys` | `bool` | `true` | List the keys of `share()`d props under `sharedProps`. |
| `ensurePagesExist` | `bool` | `false` | `render()` throws `ComponentNotFoundException` when no page file matches. |
| `ensurePagesExistInTests` | `bool` | `true` | `AssertableInertia::component()` also asserts the page file exists. |
| `pagePaths` | `list<string>` | `[ROOTPATH . 'resources/js/pages']` | Directories searched for a page file. |
| `pageExtensions` | `list<string>` | `['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue']` | Extensions, without the dot, a page file may have. |
| `ssrEnabled` | `bool` | `true` | Send pages to the SSR server. |
| `ssrUrl` | `string` | `'http://127.0.0.1:13714'` | Base URL of the production SSR server. |
| `ssrHotUrl` | `?string` | `null` | Base URL of the development SSR server; wins over the contents of `ssrHotFile` while that file exists. |
| `ssrHotFile` | `string` | `FCPATH . 'hot'` | File whose existence means the dev server is running and whose contents are its URL. |
| `ssrTimeout` | `?int` | `null` | Seconds to wait for the SSR server; `null` or `0` keeps 30. |
| `ssrEnsureBundleExists` | `bool` | `true` | Render on the server only when an SSR bundle exists (skipped in hot mode). |
| `ssrBundle` | `?string` | `null` | Path of the SSR bundle, tried before the default locations. |
| `ssrRuntime` | `string` | `'node'` | Runtime `inertia:start-ssr` runs the bundle with; `--runtime` wins. |
| `ssrEnsureRuntimeExists` | `bool` | `false` | `inertia:start-ssr` refuses to start when the runtime is not on the PATH. An absolute path is never found. |
| `ssrThrowOnError` | `bool` | `false` | A failed server render throws `SsrException` instead of falling back to client rendering. |
| `version` | `Closure(): string` | returns `''` | Produces the asset version. |

To replace the `inertia` service itself, define an `inertia()` method in your own `Config\Services`. The package also registers `inertiaSsrGateway` and `inertiaSsrState`.

## Not supported

Checked against inertia-laravel 3.5.2. These parts of it are not ported:

- DevTools (the recorder, its routes and its `devtools` setting);
- the `inertia:middleware` generator command, which has no filter equivalent;
- Blade directives and components (`@inertia`, `<x-inertia::app />`): use `inertia_app()` and `inertia_head()`;
- the `Inertia\Controller` base class and the `TestResponse` macros: use a controller method and `InertiaTestTrait`;
- the `Route::inertia($uri, $component, $props)` shortcut (a Laravel router macro): use a route closure that calls `helper('inertia')` and returns `inertia('Page')->toResponse(request(), response())`, or a controller method that returns `inertia('Page')->toResponse($this->request, $this->response)` with `inertia` loaded (for example in `BaseController::$helpers`);
- the `Request::macro('inertia')` request macro: use `$request->hasHeader('X-Inertia')`;
- `Macroable` on `ResponseFactory` and `Response`, so `Inertia::macro()` does not exist: wrap shared code in your own helper or service (the package classes are `final`);
- the `AssertableJson` fluent surface behind Laravel's `AssertableInertia` (`etc()`, `whereType()`, `whereAll()`, `first()`, `each()` and `has($key, $callback)` with a scope). `AssertableInertia` here has the fixed set of methods listed under [Testing](#testing); for anything else read the page with `inertiaPage()` or `inertiaProp()` and assert on the array;
- the middleware that runs Laravel's deferred callbacks (`EnsureDeferredCallbacksRun`), which has no CodeIgniter counterpart.

## Security

The package closes what it can on its own. These parts belong to your application.

1. **Root view.** Print the page with `inertia_app($page)` and `inertia_head($page)`, and never print `$page` yourself. `esc($page, 'attr')` prints `Array`, and a hand-written `json_encode($page)` without `JSON_HEX_TAG` lets a prop value close the script tag: stored XSS. If you must print it yourself, use `json_encode($page, JSON_HEX_TAG)` inside a JSON script tag:

   ```php
   <script data-page="app" type="application/json"><?= json_encode($page, JSON_HEX_TAG | JSON_THROW_ON_ERROR) ?></script>
   <div id="app"></div>
   ```

2. **External redirects.** `location($url)` and `inertia_location($url)` use the argument verbatim in `X-Inertia-Location` or `Location`. Never pass request input such as `?redirect=`; check it against an allow-list first.

3. **`back()` and the previous URL.** `back()`, and an empty response on an Inertia request, follow the `Referer`, then the stored previous URL (an empty response reads the stored URL first), only when it is on the site: same scheme, same effective port, and a host equal to the site's or listed in `Config\App::$allowedHostnames`. Otherwise `back()` uses the fallback, then `/`, and an empty response goes to `/`, with no fallback. This deliberately differs from Laravel, which follows any `Referer`. A URL with a backslash or a control character anywhere in it is never on the site. `back()`'s `$fallback` is used as given, so never build it from request input. Prefer an explicit `redirect()->to()` after a mutation.

4. **Props are not authorization.** Anything in `optional()`, `defer()`, `once()` or `share()` can be requested with the partial-reload headers by any client of that page, and `X-Inertia: true` only selects JSON. Authorize in the controller, before the prop is built.

5. **SSR server.**
   - Keep it on loopback (the default `ssrUrl` is `http://127.0.0.1:13714`) and never expose port 13714.
   - Its `/render` and `/shutdown` endpoints have no authentication, and the HTML it returns is printed raw by `inertia_app()`.
   - Never deploy a `public/hot` file. The gateway follows a hot file only to an http or https URL with a host, but it posts every page to that URL. Set `ssrHotUrl` to pin the development URL instead of trusting the file.
   - The hot file is not traced as tainted input by the static analysis: it is trusted deploy state, so keep it out of reach of uploads and of the web user's writes.
   - When at least one excluded path pattern is set (`withoutSsr()`, `$withoutSsr`), the gateway skips SSR (client render) for a request whose query has more pairs than PHP's `max_input_vars`.

6. **Exception pages.** The `handleExceptionsUsing()` callback receives an `ExceptionResponse`, and its `exception` holds the `Throwable`. In production, put only a status and a generic message in the props, never `getMessage()`, `getTrace()` or `getFile()`.

7. **Caching.** Responses vary on `X-Inertia` only (`Vary: X-Inertia`). Send `Cache-Control: private` or `no-store` on authenticated pages, so a shared cache or the browser does not serve one user's page to another.

8. **Cookies.** Set `Config\Cookie::$secure = true` in production so the `XSRF-TOKEN` cookie is sent over HTTPS only. The cookie is readable by script by design: the client reads it to send `X-XSRF-TOKEN`.

9. **Filter order.** Authentication filters go in `$globals['before']`, ahead of `InertiaFilter` (see [Filters](#filters)). A denial from an earlier filter ends the chain before `InertiaFilter` runs.

To report a vulnerability privately, see [SECURITY.md](SECURITY.md).

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) to set up, test and send a change, and [SECURITY.md](SECURITY.md) to report a vulnerability privately. Everyone taking part follows the [Code of Conduct](CODE_OF_CONDUCT.md). Release notes: [CHANGELOG.md](CHANGELOG.md). Upgrade steps: [UPGRADING.md](UPGRADING.md).

## License

MIT. See [LICENSE](LICENSE).
