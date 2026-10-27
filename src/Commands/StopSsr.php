<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Ejklock\Ci4Inertia\Ssr\HttpGateway;

/**
 * Asks the Inertia SSR server to shut down. The server stops by hanging up on the request, so a
 * connection closed without a reply is the success.
 */
class StopSsr extends BaseCommand
{
    private const SUCCESS           = 0;
    private const FAILURE           = 1;
    protected const CONNECT_TIMEOUT = 2;
    protected const TIMEOUT         = 5;

    protected $group       = 'Inertia';
    protected $name        = 'inertia:stop-ssr';
    protected $description = 'Stop the Inertia SSR server';
    protected $usage       = 'inertia:stop-ssr [--graceful]';

    /**
     * @var array<string, string>
     */
    protected $options = [
        '--graceful' => 'Return a successful exit code when the SSR server is not running',
    ];

    /**
     * @param array<array-key, string|null> $params
     */
    public function run(array $params): int
    {
        $errno = static::requestShutdown();

        if ($errno === CURLE_GOT_NOTHING) {
            CLI::write('Inertia SSR server stopped.');

            return self::SUCCESS;
        }

        if (array_key_exists('graceful', $params) && $errno !== CURLE_OK) {
            CLI::write('Inertia SSR server is not running.');

            return self::SUCCESS;
        }

        CLI::error('Unable to connect to Inertia SSR server.');

        return self::FAILURE;
    }

    /**
     * Asks the server to shut down and returns the curl error number of the request, printing
     * nothing. The server stops by hanging up without a reply, which curl reports as
     * `CURLE_GOT_NOTHING`. A server that does not answer in time ends the request with a timeout
     * error instead of holding the command.
     */
    public static function requestShutdown(): int
    {
        $curl = curl_init(self::shutdownUrl());
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, static::CONNECT_TIMEOUT);
        curl_setopt($curl, CURLOPT_TIMEOUT, static::TIMEOUT);
        curl_exec($curl);

        return curl_errno($curl);
    }

    /**
     * The shutdown URL of the shared gateway, so an override of its production URL is honored.
     */
    private static function shutdownUrl(): string
    {
        $gateway = service('inertiaSsrGateway');

        if ($gateway instanceof HttpGateway) {
            return $gateway->getProductionUrl('/shutdown');
        }

        return rtrim(config('Inertia')->ssrUrl, '/') . '/shutdown';
    }
}
