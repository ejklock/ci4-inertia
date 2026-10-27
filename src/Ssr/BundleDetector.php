<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Ssr;

use Ejklock\Ci4Inertia\Config\Inertia;

/**
 * Finds the built server-side rendering bundle: the configured one, then the conventional
 * locations under the application root and the public directory.
 */
final readonly class BundleDetector
{
    public function __construct(
        private Inertia $config,
        private string $rootPath = ROOTPATH,
        private string $publicPath = FCPATH,
    ) {
    }

    /**
     * The path of the first existing candidate, or null when there is no bundle.
     */
    public function detect(): ?string
    {
        $candidates = [
            $this->config->ssrBundle,
            $this->rootPath . 'bootstrap/ssr/ssr.js',
            $this->rootPath . 'bootstrap/ssr/app.js',
            $this->rootPath . 'bootstrap/ssr/ssr.mjs',
            $this->rootPath . 'bootstrap/ssr/app.mjs',
            $this->publicPath . 'js/ssr.js',
            $this->publicPath . 'js/app.js',
        ];

        foreach ($candidates as $path) {
            if ($path !== null && $path !== '' && file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
