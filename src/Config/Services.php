<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Config;

use CodeIgniter\Config\BaseService;
use CodeIgniter\Config\Services as FrameworkServices;
use CodeIgniter\HTTP\IncomingRequest;
use Ejklock\Ci4Inertia\ResponseFactory;
use Ejklock\Ci4Inertia\Ssr\BundleDetector;
use Ejklock\Ci4Inertia\Ssr\Gateway;
use Ejklock\Ci4Inertia\Ssr\HttpGateway;
use Ejklock\Ci4Inertia\Ssr\SsrState;

/**
 * Registers the `inertia` service through the framework's package service discovery; an
 * application method of the same name in its own `Config\Services` overrides it.
 */
class Services extends BaseService
{
    /**
     * The response factory for the current request, built from the `Inertia` config's root
     * view, version resolver and history-encryption default.
     */
    public static function inertia(bool $getShared = true): ResponseFactory
    {
        if ($getShared) {
            return static::getSharedInstance('inertia');
        }

        $request = service('request');
        assert($request instanceof IncomingRequest);

        $config = config('Inertia');

        return new ResponseFactory($request, $config->rootView, $config->version, $config->encryptHistory, $config->preserveBigIntegers);
    }

    /**
     * The gateway that sends pages to the SSR server; an application swaps it by declaring a
     * method of this name in its own `Config\Services`. Each dispatch builds a fresh
     * `CURLRequest`, so no header or option carries over between requests.
     */
    public static function inertiaSsrGateway(bool $getShared = true): Gateway
    {
        if ($getShared) {
            return static::getSharedInstance('inertiaSsrGateway');
        }

        $config = config('Inertia');

        return new HttpGateway(
            $config,
            static fn (array $options) => FrameworkServices::curlrequest($options, null, null, false),
            new BundleDetector($config),
        );
    }

    /**
     * The server-side rendering state of the current request: the page being rendered and the
     * response the gateway gave for it, so one page reaches the gateway at most once.
     */
    public static function inertiaSsrState(bool $getShared = true): SsrState
    {
        if ($getShared) {
            return static::getSharedInstance('inertiaSsrState');
        }

        return new SsrState();
    }
}
