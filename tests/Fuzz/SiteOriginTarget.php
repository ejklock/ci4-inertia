<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Fuzz;

use Config\App;
use Ejklock\Ci4Inertia\Support\SiteOrigin;
use LogicException;
use PhpFuzzer\Config;

/** @var Config $config */
require_once __DIR__ . '/bootstrap.php';

$siteUrl = parse_url(config(App::class)->baseURL);

/**
 * The URL as a browser reads it before it follows it: it drops tabs and line breaks, trims the
 * control characters and spaces at the ends, and reads a backslash as a slash.
 */
$asBrowserReads = static fn (string $url): string => str_replace('\\', '/', trim(str_replace(["\t", "\n", "\r"], '', $url), "\x00..\x20"));

$effectivePort = static fn (array $url, string $scheme): int => $url['port'] ?? ($scheme === 'https' ? 443 : 80);

// Any throwable is a finding: an unparseable URL is answered with null, never an exception.
$config->setAllowedExceptions([]);
$config->setMaxLen(256);
$config->setTarget(static function (string $input) use ($siteUrl, $asBrowserReads, $effectivePort): void {
    $result = SiteOrigin::urlOrNull($input);

    if ($result === null) {
        return;
    }

    $allowedHosts = array_map(strtolower(...), [$siteUrl['host'], ...config(App::class)->allowedHostnames]);
    $parsed       = parse_url($asBrowserReads($input));

    // Schemes are case-insensitive, and a scheme-relative URL on the site's host keeps the page's scheme.
    $scheme = strtolower(($parsed ?: [])['scheme'] ?? $siteUrl['scheme']);

    if ($result !== $input
        || $parsed === false
        || ! in_array(strtolower($parsed['host'] ?? ''), $allowedHosts, true)
        || $scheme !== $siteUrl['scheme']
        || $effectivePort($parsed, $scheme) !== $effectivePort($siteUrl, $siteUrl['scheme'])) {
        throw new LogicException('Accepted as on the site but the browser would go elsewhere, input 0x' . bin2hex($input));
    }
});
