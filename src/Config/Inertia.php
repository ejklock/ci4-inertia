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
     * The view rendered for a non-Inertia visit; it receives the page object as JSON in
     * its `page` variable and places it, HTML-attribute-escaped, in a `data-page` attribute.
     */
    public string $rootView = 'app';

    /**
     * Whether every page asks the client to encrypt its history state; a response overrides it
     * for the renders that follow with `ResponseFactory::encryptHistory()`.
     */
    public bool $encryptHistory = false;

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
