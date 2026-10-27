<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Ejklock\Ci4Inertia\Config\Inertia as InertiaConfig;
use Ejklock\Ci4Inertia\Ssr\BundleDetector;
use Ejklock\Ci4Inertia\Ssr\SsrException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs the SSR bundle with the configured runtime and relays its output until it exits. The
 * protected methods are the points a test or an application overrides.
 */
class StartSsr extends BaseCommand
{
    private const SUCCESS = 0;
    private const FAILURE = 1;

    protected $group       = 'Inertia';
    protected $name        = 'inertia:start-ssr';
    protected $description = 'Start the Inertia SSR server';
    protected $usage       = 'inertia:start-ssr [--runtime <runtime>]';

    /**
     * @var array<string, string>
     */
    protected $options = [
        '--runtime' => 'The runtime to use (e.g. `node` or `bun`)',
    ];

    /**
     * @param array<array-key, string|null> $params
     */
    public function run(array $params): int
    {
        $config = config('Inertia');

        if (! $config->ssrEnabled) {
            CLI::error('Inertia SSR is not enabled. Enable it via the `Config\Inertia::$ssrEnabled` config option.');

            return self::FAILURE;
        }

        $bundle     = $this->bundleDetector($config)->detect();
        $configured = $config->ssrBundle;
        $hasConfig  = $configured !== null && $configured !== '';

        if ($bundle === null) {
            CLI::error(
                $hasConfig
                    ? 'Inertia SSR bundle not found at the configured path: "' . $configured . '"'
                    : 'Inertia SSR bundle not found. Set the correct Inertia SSR bundle path in your `Config\Inertia::$ssrBundle` config.',
            );

            return self::FAILURE;
        }

        if ($hasConfig && $bundle !== $configured) {
            CLI::write('Inertia SSR bundle not found at the configured path: "' . $configured . '"', 'yellow');
            CLI::write('Using a default bundle instead: "' . $bundle . '"', 'yellow');
        }

        $runtime = $this->runtimeOption($params) ?? $config->ssrRuntime;

        if ($config->ssrEnsureRuntimeExists && ! $this->runtimeExists($runtime)) {
            CLI::error('SSR runtime "' . $runtime . '" could not be found.');

            return self::FAILURE;
        }

        $this->stopRunningServer();

        $process = $this->makeProcess([$runtime, $bundle]);
        $process->setTimeout(null);
        $process->start();

        $this->stopOnSignals($process);

        foreach ($process as $type => $data) {
            if ($type === Process::OUT) {
                CLI::write(trim($data));

                continue;
            }

            CLI::error(trim($data));
            log_message('error', (new SsrException($data))->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * The process that runs the bundle. The command holds the runtime and the bundle as separate
     * arguments, so neither reaches a shell.
     *
     * @param list<string> $command
     */
    protected function makeProcess(array $command): Process
    {
        return new Process($command);
    }

    /**
     * The runtime given on the command line, or null when there is none or it is empty. The parser
     * keeps `--runtime bun` as the key `runtime` and `--runtime=bun` as the key `runtime=bun`, so
     * both forms are read.
     *
     * @param array<array-key, string|null> $params
     */
    private function runtimeOption(array $params): ?string
    {
        foreach ($params as $key => $value) {
            if ($key === 'runtime' && $value !== null && $value !== '') {
                return $value;
            }

            if (is_string($key) && str_starts_with($key, 'runtime=') && strlen($key) > strlen('runtime=')) {
                return substr($key, strlen('runtime='));
            }
        }

        return null;
    }

    /**
     * Whether the runtime is a name found on the PATH. The finder only searches the PATH, so an
     * absolute path is never found.
     */
    private function runtimeExists(string $runtime): bool
    {
        return (new ExecutableFinder())->find($runtime) !== null;
    }

    protected function bundleDetector(InertiaConfig $config): BundleDetector
    {
        return new BundleDetector($config);
    }

    /**
     * Stops the process on SIGINT, SIGQUIT and SIGTERM, where the pcntl extension is loaded.
     */
    protected function stopOnSignals(Process $process): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        $stop = static function () use ($process): void {
            $process->stop();
        };

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGQUIT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }

    /**
     * Shuts down a server that is already running. It sends the request without running the stop
     * command, whose messages spark would print straight to the output stream.
     */
    protected function stopRunningServer(): void
    {
        StopSsr::requestShutdown();
    }
}
