<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Support;

use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\URI;
use Config\App;

/**
 * Decides whether a URL taken from the request or the session is on the site's own origin,
 * so a redirect built from it cannot leave the site.
 *
 * @internal
 */
final class SiteOrigin
{
    /**
     * The URL itself when its scheme, host and port are the site's own, as `site_url()` builds
     * them from the base URL and the allowed hostnames, else null. A URL that cannot be parsed
     * is not on the site. A URL with a backslash or a control character is not either: PHP reads
     * `http://evil.example\@example.com/` as host `example.com`, but a browser follows it to
     * `evil.example`, so the two parsers disagree about where it goes.
     */
    public static function urlOrNull(string $url): ?string
    {
        if (preg_match('/[\\\\\x00-\x1f\x7f]/', $url) === 1) {
            return null;
        }

        try {
            $target = new URI($url);
            $site   = new URI(site_url('/'));
        } catch (HTTPException) {
            return null;
        }

        $allowedHosts = array_map(strtolower(...), [$site->getHost(), ...config(App::class)->allowedHostnames]);
        $sameOrigin   = $target->getScheme() === $site->getScheme()
            && self::effectivePort($target) === self::effectivePort($site)
            && in_array(strtolower($target->getHost()), $allowedHosts, true);

        return $sameOrigin ? $url : null;
    }

    /**
     * The explicit port of the URI, or the default one of its scheme.
     */
    private static function effectivePort(URI $uri): int
    {
        return $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
    }

    private function __construct()
    {
    }
}
