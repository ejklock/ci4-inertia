<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Config;

use Closure;
use CodeIgniter\Config\BaseConfig;

/**
 * Defaults read by the Inertia response adapter (`Ejklock\Ci4Inertia\ResponseFactory` and
 * `Ejklock\Ci4Inertia\InertiaResponse`) to build the page object and to pick the root document
 * for a non-Inertia visit. An application overrides them by extending this class as its
 * own `Config\Inertia`, which `config('Inertia')` resolves ahead of this one.
 */
class Inertia extends BaseConfig
{
    /**
     * The view rendered for a non-Inertia visit; it receives the page object as an
     * array in its `page` variable and prints it with `inertia_app($page)`.
     */
    public string $rootView = 'app';

    /**
     * Whether every page asks the client to encrypt its history state; a response overrides it
     * for the renders that follow with `ResponseFactory::encryptHistory()`.
     */
    public bool $encryptHistory = false;

    /**
     * Whether every page sends integers outside JavaScript's safe range (beyond 2^53 - 1) as
     * `{"$bigint": "<digits>"}` markers in its props and flash data and carries
     * `preserveBigIntegers: true`, so the client can revive them without losing precision; a
     * single response opts in or out with `InertiaResponse::preserveBigIntegers()`.
     */
    public bool $preserveBigIntegers = false;

    /**
     * Whether `InertiaFilter` records an Inertia `GET` as CodeIgniter's previous URL
     * (`_ci_previous_url`), so `redirect()->back()` returns to the page the visitor is on.
     * CodeIgniter stores that URL only for non-AJAX HTML responses, so after an Inertia visit
     * `back()` would return to the last full page load; this is why it defaults on here while
     * inertia-laravel defaults `store_previous_url` off, where `back()` reads the `Referer`
     * header first and is already correct. Only an Inertia `GET` answered with a rendered page
     * is recorded; a redirect, an empty `200`, a `409`, a partial reload of the rendered
     * component, a prefetch and a Precognition request never are.
     */
    public bool $storePreviousUrl = true;

    /**
     * Whether each page lists the top-level keys of the props registered through
     * `ResponseFactory::share()` under `sharedProps`, so the client can carry them over during
     * instant visits.
     */
    public bool $exposeSharedPropKeys = true;

    /**
     * Whether `ResponseFactory::render()` throws `ComponentNotFoundException` when no file under
     * `$pagePaths` with one of `$pageExtensions` matches the component name.
     */
    public bool $ensurePagesExist = false;

    /**
     * Whether `AssertableInertia::component()` also asserts that the page file exists; a call
     * passes its own `$shouldExist` argument to override it.
     */
    public bool $ensurePagesExistInTests = true;

    /**
     * The directories searched for a page file by the two checks above.
     *
     * @var list<string>
     */
    public array $pagePaths = [ROOTPATH . 'resources/js/pages'];

    /**
     * The extensions, without the dot, a page file may have.
     *
     * @var list<string>
     */
    public array $pageExtensions = ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'];

    /**
     * Whether pages are sent to an Inertia SSR server to be rendered ahead of the client.
     */
    public bool $ssrEnabled = true;

    /**
     * The base URL of the production SSR server.
     */
    public string $ssrUrl = 'http://127.0.0.1:13714';

    /**
     * The base URL of the development SSR server; when set it wins over the contents of
     * `$ssrHotFile` while that file exists.
     */
    public ?string $ssrHotUrl = null;

    /**
     * The file whose existence means the development server is running and whose contents are
     * its base URL.
     */
    public string $ssrHotFile = FCPATH . 'hot';

    /**
     * The seconds to wait for the SSR server; null or 0 keeps the default of 30. Connecting is
     * always limited to 10 seconds.
     */
    public ?int $ssrTimeout = null;

    /**
     * Whether a page is rendered on the server only when an SSR bundle exists (skipped in hot mode).
     */
    public bool $ssrEnsureBundleExists = true;

    /**
     * The path of the SSR bundle, tried before the default locations.
     */
    public ?string $ssrBundle = null;

    /**
     * Whether a failed server render throws `Ejklock\Ci4Inertia\Ssr\SsrException` instead of
     * falling back to client rendering.
     */
    public bool $ssrThrowOnError = false;

    /**
     * Resolves the asset version sent on every page object. A client whose
     * `X-Inertia-Version` differs from this value is running assets from a previous deploy
     * and is forced into a hard reload rather than fed data from the new backend.
     *
     * @var Closure(): string
     */
    public Closure $version;

    /**
     * Sets the default version resolver to an empty string: a package knows nothing of the
     * application's asset pipeline, so the application supplies a real fingerprint.
     */
    public function __construct()
    {
        parent::__construct();

        $this->version = static fn (): string => '';
    }
}
