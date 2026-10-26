<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Config;

use CodeIgniter\Config\BaseService;
use CodeIgniter\HTTP\IncomingRequest;
use Ejklock\Ci4Inertia\ResponseFactory;

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

        return new ResponseFactory($request, $config->rootView, $config->version, $config->encryptHistory);
    }
}
