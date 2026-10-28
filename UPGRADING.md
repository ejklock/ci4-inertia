# Upgrading from 0.2.x to 3.x

Version 3.0.0 ports inertia-laravel 3.5.2 for the Inertia v3 client. It is a major release: a 0.2.x app needs the steps below. Each step shows the code before and after. The full list of changes is in [CHANGELOG.md](CHANGELOG.md).

Do the steps in order. Steps 1 to 3 are needed by every app. Steps 4 to 7 apply only if your code uses what they name. Step 8 applies to apps with tests. Steps 9 and 10 are behavior changes to review.

This guide starts at 0.2.x. From 0.1.x, read the 0.2.0 entry of the changelog first.

## Step 1. Update the package

Change the constraint and update:

```bash
composer require ejklock/ci4-inertia:^3.0
```

The requirements are PHP `^8.2` and `codeigniter4/framework` `^4.7`, as in 0.2.x. The package now also requires `symfony/process` (`^7.2 || ^8.0`) for the `inertia:start-ssr` command. Composer installs it with the package. If your app pins `symfony/process` to another series, lift the pin.

## Step 2. Update the client packages

The 3.x package needs the Inertia v3 client. A 2.x client reads the page from the `data-page` attribute of the mount element, which 3.x no longer prints, so it cannot find the page.

Before:

```bash
npm install @inertiajs/vue3@^2
```

After:

```bash
npm install @inertiajs/vue3@^3
```

Use `@inertiajs/react` or `@inertiajs/svelte` in the same way. The client code changes (axios removed, renamed events, config file and others) are listed in Inertia's own guide. Follow it instead of this one for the JavaScript: <https://inertiajs.com/docs/v3/getting-started/upgrade-guide>.

`XsrfTokenCookieFilter` is unchanged. The v3 built-in client reads the `XSRF-TOKEN` cookie it sets and sends the token back in the `X-XSRF-TOKEN` header, the same header axios sent, as Inertia's CSRF guide describes: <https://inertiajs.com/docs/v3/security/csrf-protection>. An app that followed the 0.2 setup, with `Config\Security::$headerName = 'X-XSRF-TOKEN'`, keeps working. With CodeIgniter's default `X-CSRF-TOKEN`, every Inertia `POST`, `PUT`, `PATCH` and `DELETE` fails the CSRF check; see the [README's CSRF section](README.md#csrf).

## Step 3. Update the root view

The root view gets the page as an array in `$page`, not as a JSON string. Print it with `inertia_app($page)`. The helper is loaded for you.

Before (`app/Views/app.php`):

```php
<body>
<div id="app" data-page="<?= esc($page, 'attr') ?>"></div>
</body>
```

After:

```php
<body>
<?= inertia_app($page) ?>
</body>
```

`inertia_app($page)` prints `<script data-page="app" type="application/json">...</script><div id="app"></div>`. The id is `app` unless you pass another as the second argument: `inertia_app($page, 'root')`. The mount element is part of the output, so remove your own `<div id="app">`. The v3 client reads the JSON from the script element.

When an SSR server renders the page, `inertia_app($page)` prints the server-rendered body instead. Print its head in the `<head>` of the view with `inertia_head($page)`. It prints nothing, or the fallback you pass, when the page was not rendered on the server:

```php
<head>
    <meta charset="utf-8">
    <title>My app</title>
    <?= inertia_head($page) ?>
</head>
```

SSR is optional. Nothing renders on the server unless an SSR bundle exists or the Vite hot file does (in hot mode the bundle check is skipped). See the SSR section of the README for the settings.

## Step 4. Replace the removed prop classes

`LazyProp`, `ResolvableProp`, `MergeState` and `MergeMode` are removed. A closure or an invokable object is a prop now, and it is called only when the prop is sent.

Before:

```php
use Ejklock\Ci4Inertia\LazyProp;

return $inertia->render('Dashboard', [
    'stats' => new LazyProp(fn () => $this->loadStats()),
])->toResponse($this->request, $this->response);
```

After:

```php
return $inertia->render('Dashboard', [
    'stats' => fn () => $this->loadStats(),
])->toResponse($this->request, $this->response);
```

The closure is resolved on a full render and on a partial reload that names it, as `LazyProp` was. A prop whose value is itself a closure, such as a service factory, is now called when the prop is sent.

If you want a prop the first render leaves out, use `optional()`:

```php
'audit' => $inertia->optional(fn () => $this->loadAudit()),
```

Other changes to the prop classes:

- A custom class that implemented `ResolvableProp` with `resolve()` implements `__invoke()` instead, or becomes a closure.
- `DeferProp`, `OptionalProp` and `AlwaysProp` are resolved with `__invoke()`, not `resolve()`.
- `new MergeProp($value)` takes any value. `new DeferProp($callback, $group, $rescue)` replaces `new DeferProp($callback, $group, $mergeState)`. Build both through `$inertia->merge()` and `$inertia->defer()` and nothing changes.
- A prop value is no longer validated. A bare closure used to throw `InvalidArgumentException`. To keep the 0.2 guard that no entity reaches the page, set `$strictProps` to `true` in your `Config\Inertia`; see [Strict props](README.md#strict-props).
- The items of `X-Inertia-Partial-Data` and `X-Inertia-Partial-Except` are no longer trimmed.
- Only a closure or an invokable object is called as a prop callback. A string or an array callable is sent as it is.

## Step 5. Pass match fields as an array

`matchOn()` takes one field name or an array of names. It no longer takes a variadic list.

Before:

```php
'users' => $inertia->merge($users)->matchOn('id', 'slug'),
```

After:

```php
'users' => $inertia->merge($users)->matchOn(['id', 'slug']),
```

PHP does not fail on the old call: it ignores the second argument, so only `id` would be matched. A call with one field, `matchOn('id')`, is unchanged.

## Step 6. Expect a 302 for a non-Inertia PUT, PATCH or DELETE

`InertiaFilter` used to turn every `302` after a `PUT`, `PATCH` or `DELETE` into a `303`. It now does so only for an Inertia request, as inertia-laravel does. A plain form or API client sending `PUT`, `PATCH` or `DELETE` keeps the `302` your controller returned.

Nothing changes for Inertia requests. If a non-Inertia client of yours relied on the `303`, return it yourself:

```php
return redirect()->to('/users', 303);
```

`EnsureGetOnRedirectFilter`, which applies the rewrite to a route group without the rest of `InertiaFilter`, also acts only on an Inertia request. It does not restore the `303` for a non-Inertia client.

## Step 7. Read one error message per field

The shared `errors` prop sends one message per field. It used to send the value stored in the session as it was.

Before, with a field that holds a list of messages in the session:

```json
{ "email": ["Required", "Must be an email"] }
```

After:

```json
{ "email": "Required" }
```

To keep every message as a list, set `$withAllErrors` in your filter that extends `InertiaFilter`:

```php
class AppInertiaFilter extends InertiaFilter
{
    protected bool $withAllErrors = true;
}
```

The prop also honors the `X-Inertia-Error-Bag` header, which scopes the errors under the bag name. A request that carries no session cookie gets an empty object, and no session is started to look for errors.

## Step 8. Update the tests

`InertiaTestTrait` and `AssertableInertia` read the page from the script element of step 3. A test that calls `assertInertia()` on a first-visit response needs the root view of step 3, or the page is not found. Tests that send the `X-Inertia` header are not affected.

`AssertableInertia::component()` now also checks that the page file exists. It looks for `<name>.<extension>` under `Config\Inertia::$pagePaths`, whose default is `ROOTPATH . 'resources/js/pages'`, with the extensions in `$pageExtensions` (`js`, `jsx`, `svelte`, `ts`, `tsx`, `vue`). The 0.2.x quickstart put pages in `resources/js/Pages`. On a case-sensitive file system the default path does not match that folder. Point the setting at your pages in your own `Config\Inertia`:

Before (nothing to configure):

```php
$page->component('Users/Index');
```

After, one of these:

```php
// app/Config/Inertia.php
public array $pagePaths = [ROOTPATH . 'resources/js/Pages'];
```

```php
// app/Config/Inertia.php: stop checking in tests
public bool $ensurePagesExistInTests = false;
```

```php
// or per call
$page->component('Users/Index', shouldExist: false);
```

`ResponseFactory::render()` checks the file only when `$ensurePagesExist` is on. It is off by default.

## Step 9. Review redirects back

`Inertia::back()` is new. It goes to the `Referer`, else the stored previous URL, else the fallback you pass, else the site root. The redirect `InertiaFilter` sends for an empty Inertia `200` reads the stored previous URL first, then the `Referer`, then the site root, with no fallback. Both follow a `Referer` or a stored URL only when its scheme, host and port are the site's own. A URL from another origin is skipped, and so is one with a backslash or a control character. This is stricter than inertia-laravel, which follows any `Referer`. The fallback you pass is used as given.

Before:

```php
return redirect()->back();
```

After, if you want the origin check:

```php
return service('inertia')->back(302, [], '/users');
```

`redirect()->back()` still works. It follows CodeIgniter's own rules, not this check: it reads the stored previous URL first, then any `Referer` with no origin check, then the site root.

`Config\Inertia::$storePreviousUrl` is on by default. `InertiaFilter` then records a rendered Inertia `GET` as CodeIgniter's previous URL when the request already carries a session. Set it to `false` in your own `Config\Inertia` to keep CodeIgniter's behavior, where only a full page load is recorded.

## Step 10. Read dotted prop keys as nested props

A prop key with a dot in it is now a path. `share('auth.user', $user)` and a render prop `'auth.user' => $user` reach the client as `{"auth": {"user": ...}}`. In 0.2.x the key was sent as it was, `{"auth.user": ...}`. Both forms nest: `share()` with a string key sets the value by path, and when the props are resolved every dotted top-level key of the shared props and of the render props is unpacked, a dotted key of `share([...])` included. A page that read `props['auth.user']` gets `undefined` now.

Before, `share()` (the client got `{"auth.user": "Ada"}`):

```php
service('inertia')->share('auth.user', 'Ada');
```

Before, render prop (the client got `{"auth.user": "Ada"}`):

```php
return $inertia->render('Dashboard', ['auth.user' => 'Ada'])->toResponse($this->request, $this->response);
```

After, for both (the client gets `{"auth": {"user": "Ada"}}`), the page reads:

```js
props.auth.user
```

If a page must keep a flat key, rename it without a dot (`auth_user`) and read the new name. A dotted key and its parent in the same props merge: `'auth' => ['id' => 1]` with `'auth.name' => 'Ada'` gives `{"auth": {"id": 1, "name": "Ada"}}`, and a dotted key over a scalar replaces the scalar. Across sources the order matters: `share('auth.user', $user)` nests at once, so a render prop `'auth' => [...]` then replaces the whole shared `auth` and drops `auth.user`, while `share(['auth.user' => $user])` is unpacked after the merge and keeps both. A shared dotted key lists its top-level key (`auth`) under `sharedProps`; a dotted render prop is not listed there.

## Changed public symbols

Every public symbol of `src/` whose signature or default changed between 0.2.0 and 3.x, with the step that explains it. Symbols that are new are listed in the changelog.

| Symbol | 0.2.x | 3.x | Step |
| --- | --- | --- | --- |
| Root view variable `$page` | JSON string | array | 3 |
| `LazyProp`, `ResolvableProp`, `MergeState`, `MergeMode` | present | removed | 4 |
| `resolve()` on `OptionalProp`, `AlwaysProp`, `DeferProp`, `MergeProp` | `resolve(): mixed` | `__invoke(): mixed` | 4 |
| `AlwaysProp::__construct` | `(callable $callback)` | `(mixed $source)` | 4 |
| `MergeProp::__construct` | `(array\|Closure $value, ?MergeState $state = null)` | `(mixed $value)` | 4 |
| `DeferProp::__construct` | `(callable, string $group = 'default', ?MergeState $mergeState = null)` | `(callable, ?string $group = null, bool $rescue = false)` | 4 |
| `DeferProp::mergeState()`, `MergeProp::mergeState()` | public | `DeferProp`: protected; `MergeProp`: removed | 4 |
| `ResponseFactory::merge()`, `deepMerge()` and the `Inertia` shortcuts | `(array\|Closure $value)` | `(mixed $value)` | 4 |
| `ResponseFactory::defer()` and `Inertia::defer()` | `(callable, string $group = 'default')` | `(callable, string $group = 'default', bool $rescue = false)` | 4 |
| `MergeProp::matchOn()`, `DeferProp::matchOn()` | `(string $field, string ...$fields)` | `(array\|string $matchOn)` | 5 |
| `MergeProp::prepend()`, `DeferProp::prepend()` | `prepend()` | `prepend(array\|bool\|string $path = true, ?string $matchOn = null)` | none, a call without arguments is unchanged |
| `InertiaFilter::after()` | `302` to `303` for any `PUT`, `PATCH`, `DELETE` | only for an Inertia request | 6 |
| `InertiaFilter::share()` `errors` prop | stored value | one message per field | 7 |
| `AssertableInertia::component()` | `(string $expected)` | `(string $expected, ?bool $shouldExist = null)`, checks the file by default | 8 |
| `Config\Inertia::$storePreviousUrl` | not present | `true` | 9 |
| `Config\Inertia::$ensurePagesExistInTests` | not present | `true` | 8 |
| `ResponseFactory::render()`, `Inertia::render()` | `(string $component, array $props = [])` | `(BackedEnum\|string\|UnitEnum $component, array\|ProvidesInertiaProperties $props = [])` | none, widened |
| `ResponseFactory::share()`, `Inertia::share()` | `(array\|string $key, mixed $value = null)` | `(array\|ProvidesInertiaProperties\|string $key, mixed $value = null)` | none, widened |
| `ResponseFactory::location()`, `Inertia::location()` | `(string $url)` | `(RedirectResponse\|string $url)` | none, widened |
| `InertiaFilter::applyVaryHeader()` | private, non-static | public static | none, rename a subclass method of that name |
| `ResponseFactory::__construct`, `InertiaResponse::__construct` | 4 and 6 parameters | 5 and 11 parameters, each with new trailing ones | none, build them with `service('inertia')` and `render()` |
| `InertiaResponse` | `final readonly class` | `final class` | none |
| `ResponseFactory::share()` with a dotted key, and a dotted top-level render prop key | sent as the flat key `{"auth.user": ...}` | nested `{"auth": {"user": ...}}` | 10 |
| Page object `url` | route path plus query | request URI as sent | none |

An app that only calls `service('inertia')->render()`, `defer()`, `merge()`, `share()` and `location()` with the arguments it used before is not affected by the rows marked "none".
