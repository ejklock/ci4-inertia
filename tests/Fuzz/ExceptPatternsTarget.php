<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Fuzz;

use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use Config\App as AppConfig;
use Ejklock\Ci4Inertia\Config\Inertia as InertiaConfig;
use Ejklock\Ci4Inertia\Ssr\BundleDetector;
use Ejklock\Ci4Inertia\Ssr\HttpGateway;
use LogicException;
use PhpFuzzer\Config;

/** @var Config $config */
require_once __DIR__ . '/bootstrap.php';

/**
 * The excluded-path check with its protected entry point made callable.
 */
final class FuzzedHttpGateway extends HttpGateway
{
    public function isExcluded(IncomingRequest $request): bool
    {
        return $this->inExceptArray($request);
    }
}

/**
 * A request whose raw REQUEST_URI is the attacker's string, as the web server hands it over.
 */
final class FuzzedRequest extends IncomingRequest
{
    public function __construct(AppConfig $config, URI $uri, private readonly string $requestUri)
    {
        parent::__construct($config, $uri, null, new UserAgent());
    }

    public function getServer($index = null, $filter = null, $flags = null)
    {
        return $index === 'REQUEST_URI' ? $this->requestUri : parent::getServer($index, $filter, $flags);
    }
}

$inertia = new InertiaConfig();
$gateway = new FuzzedHttpGateway(
    $inertia,
    static fn (array $options) => throw new LogicException('The exclusion check must not call the SSR server.'),
    new BundleDetector($inertia),
);
$gateway->except([
    'admin/*',
    '*/edit',
    'a*b*c',
    'http://example.com/search?*',
    'http://example.com/a?b=1&c=*',
]);

$app            = new AppConfig();
$app->baseURL   = 'http://example.com/';
$app->indexPage = '';

// Any throwable is a finding: the check runs on every request and must answer, never fail.
$config->setAllowedExceptions([]);
$config->setMaxLen(256);
$config->setTarget(static function (string $input) use ($gateway, $app): void {
    try {
        // The framework builds the request's URI from the path alone and keeps the query in REQUEST_URI.
        $uri = new SiteURI($app, explode('?', $input, 2)[0]);
    } catch (HTTPException) {
        // The framework refuses the request before the package sees it.
        return;
    }

    $gateway->isExcluded(new FuzzedRequest($app, $uri, '/' . $input));
});
