<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Ejklock\Ci4Inertia\Ssr\HasHealthCheck;

/**
 * Reports whether the Inertia SSR server answers its health check.
 */
class CheckSsr extends BaseCommand
{
    private const SUCCESS = 0;
    private const FAILURE = 1;

    protected $group       = 'Inertia';
    protected $name        = 'inertia:check-ssr';
    protected $description = 'Check the health of the Inertia SSR server';
    protected $usage       = 'inertia:check-ssr';

    /**
     * @param array<array-key, string|null> $params
     */
    public function run(array $params): int
    {
        $gateway = service('inertiaSsrGateway');

        if (! $gateway instanceof HasHealthCheck) {
            CLI::error('The SSR gateway does not support health checks.');

            return self::FAILURE;
        }

        if ($gateway->isHealthy()) {
            CLI::write('Inertia SSR server is running.');

            return self::SUCCESS;
        }

        CLI::error('Inertia SSR server is not running.');

        return self::FAILURE;
    }
}
