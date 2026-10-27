<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Commands;

use CodeIgniter\CLI\Commands;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use CodeIgniter\Test\TestLogger;
use Config\Services;
use Ejklock\Ci4Inertia\Commands\StartSsr;
use Ejklock\Ci4Inertia\Config\Inertia as InertiaConfig;
use Ejklock\Ci4Inertia\Ssr\BundleDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

/**
 * Verifies `spark inertia:start-ssr` with a stand-in process, so no SSR runtime runs: the checks
 * before the start, the runtime and bundle it runs, the output it relays and the stop it issues first.
 *
 * @internal
 */
final class StartSsrTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    private string $dir;
    private InertiaConfig $config;

    /**
     * @var list<string>
     */
    private array $events = [];

    /**
     * @var list<string>|null
     */
    private ?array $requested = null;

    private bool $realStop    = false;
    private ?Process $started = null;
    private Process $stand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = rtrim(sys_get_temp_dir(), '/') . '/ssr-start-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/root/bootstrap/ssr', 0o777, true);
        mkdir($this->dir . '/public', 0o777, true);
        touch($this->dir . '/root/bootstrap/ssr/ssr.mjs');

        $this->config                         = config('Inertia');
        $this->config->ssrEnabled             = true;
        $this->config->ssrBundle              = null;
        $this->config->ssrRuntime             = 'node';
        $this->config->ssrEnsureRuntimeExists = false;

        $this->events    = [];
        $this->requested = null;
        $this->started   = null;
        $this->stand     = $this->standIn('');

        $this->realStop = false;

        Services::injectMock('commands', new class ($this) extends Commands {
            public function __construct(private readonly StartSsrTest $test)
            {
                parent::__construct();
            }

            public function run(string $command, array $params)
            {
                return $command === 'inertia:start-ssr' ? $this->test->commandUnderTest()->run($params) : 0;
            }
        });
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        Factories::reset('config');

        foreach (array_merge([$this->dir . '/root/bootstrap/ssr/ssr.mjs'], glob($this->dir . '/public/*') ?: []) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach ([$this->dir . '/root/bootstrap/ssr', $this->dir . '/root/bootstrap', $this->dir . '/root', $this->dir . '/public', $this->dir] as $path) {
            if (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }

    public function testItRefusesToStartWhenSsrIsDisabled(): void
    {
        $this->config->ssrEnabled = false;

        $exit = $this->start();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Inertia SSR is not enabled. Enable it via the `Config\Inertia::$ssrEnabled` config option.', $this->getStreamFilterBuffer());
        $this->assertNull($this->requested);
    }

    public function testItNamesTheSettingWhenNoBundleIsFoundAndNoneIsConfigured(): void
    {
        unlink($this->dir . '/root/bootstrap/ssr/ssr.mjs');

        $exit = $this->start();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Inertia SSR bundle not found. Set the correct Inertia SSR bundle path in your `Config\Inertia::$ssrBundle` config.', $this->getStreamFilterBuffer());
        $this->assertNull($this->requested);
    }

    public function testItNamesTheConfiguredPathWhenNoBundleIsFound(): void
    {
        unlink($this->dir . '/root/bootstrap/ssr/ssr.mjs');
        $this->config->ssrBundle = $this->dir . '/missing.js';

        $exit = $this->start();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Inertia SSR bundle not found at the configured path: "' . $this->dir . '/missing.js"', $this->getStreamFilterBuffer());
        $this->assertStringNotContainsString('Set the correct', $this->getStreamFilterBuffer());
        $this->assertNull($this->requested);
    }

    public function testItWarnsAndRunsTheDefaultBundleWhenTheConfiguredOneIsMissing(): void
    {
        $this->config->ssrBundle = $this->dir . '/missing.js';

        $exit = $this->start();

        $default = $this->dir . '/root/bootstrap/ssr/ssr.mjs';
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Inertia SSR bundle not found at the configured path: "' . $this->dir . '/missing.js"', $this->getStreamFilterBuffer());
        $this->assertStringContainsString('Using a default bundle instead: "' . $default . '"', $this->getStreamFilterBuffer());
        $this->assertSame(['node', $default], $this->requested);
    }

    public function testItDoesNotWarnWhenTheConfiguredBundleIsUsed(): void
    {
        $configured = $this->dir . '/public/custom.js';
        touch($configured);
        $this->config->ssrBundle = $configured;

        $exit = $this->start();

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('Using a default bundle', $this->getStreamFilterBuffer());
        $this->assertSame(['node', $configured], $this->requested);

        unlink($configured);
    }

    public function testTheConfiguredRuntimeIsUsedByDefault(): void
    {
        $this->start();

        $this->assertSame(['node', $this->dir . '/root/bootstrap/ssr/ssr.mjs'], $this->requested);
        $this->assertNull($this->started?->getTimeout());
    }

    public function testTheRuntimeOptionOverridesTheConfiguredRuntime(): void
    {
        $this->config->ssrRuntime = 'deno';

        $this->start(['runtime' => 'bun']);

        $this->assertSame(['bun', $this->dir . '/root/bootstrap/ssr/ssr.mjs'], $this->requested);
    }

    public function testTheConfiguredRuntimeIsUsedWhenTheRuntimeOptionHasNoValue(): void
    {
        $this->config->ssrRuntime = 'deno';

        $this->start(['runtime' => null]);

        $this->assertSame(['deno', $this->dir . '/root/bootstrap/ssr/ssr.mjs'], $this->requested);
    }

    public function testARuntimeValueWithShellCharactersIsASingleArgument(): void
    {
        $this->start(['runtime' => 'node; rm -rf /']);

        $this->assertSame(['node; rm -rf /', $this->dir . '/root/bootstrap/ssr/ssr.mjs'], $this->requested);
    }

    public function testItFailsWhenARequiredRuntimeIsNotOnThePath(): void
    {
        $this->config->ssrEnsureRuntimeExists = true;

        $exit = $this->start(['runtime' => 'ssr-runtime-that-does-not-exist']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('SSR runtime "ssr-runtime-that-does-not-exist" could not be found.', $this->getStreamFilterBuffer());
        $this->assertNull($this->requested);
        $this->assertSame([], $this->events);
    }

    public function testItProceedsWhenARequiredRuntimeIsANameFoundOnThePath(): void
    {
        $this->config->ssrEnsureRuntimeExists = true;

        $exit = $this->start(['runtime' => 'php']);

        $this->assertSame(0, $exit);
        $this->assertSame(['php', $this->dir . '/root/bootstrap/ssr/ssr.mjs'], $this->requested);
    }

    public function testItFailsWhenARequiredRuntimeIsGivenAsAPathEvenIfItIsExecutable(): void
    {
        $this->config->ssrEnsureRuntimeExists = true;

        $exit = $this->start(['runtime' => PHP_BINARY]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('SSR runtime "' . PHP_BINARY . '" could not be found.', $this->getStreamFilterBuffer());
        $this->assertNull($this->requested);
    }

    public function testItProceedsWithAnAbsentRuntimeWhenItIsNotRequiredToExist(): void
    {
        $exit = $this->start(['runtime' => 'ssr-runtime-that-does-not-exist']);

        $this->assertSame(0, $exit);
        $this->assertNotNull($this->requested);
    }

    public function testItPrintsTheOutputAndReportsTheErrorsOfTheProcess(): void
    {
        $this->stand = $this->standIn('fwrite(STDOUT, "out\n"); fwrite(STDERR, "err\n");');

        $exit = $this->start();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('out', $this->getStreamFilterBuffer());
        $this->assertStringContainsString('err', $this->getStreamFilterBuffer());
        $this->assertDoesNotMatchRegularExpression("/out\n(\e\\[0m)?\n/", $this->getStreamFilterBuffer());
        $this->assertDoesNotMatchRegularExpression("/err\n(\e\\[0m)?\n/", $this->getStreamFilterBuffer());
        $this->assertLogged('error', "err\n");
        $this->assertNotLogged('error', "out\n");
    }

    public function testTheServerIsStoppedBeforeTheProcessStarts(): void
    {
        $this->start();

        $this->assertSame(['stop', 'start'], $this->events);
    }

    public function testTheRunningServerIsAskedToShutDownWithoutPrintingAnything(): void
    {
        $this->realStop = true;
        $listener       = new Process([PHP_BINARY, '-r', <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:0');
            echo stream_socket_get_name($server, false), "\n";
            $client = @stream_socket_accept($server, 30);
            $head = '';
            while ($client && ! str_contains($head, "\r\n\r\n")) {
                $chunk = fread($client, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $head .= $chunk;
            }
            echo strtok($head, "\r\n"), "\n";
            PHP]);
        $listener->start();

        try {
            $deadline = microtime(true) + 10.0;
            $output   = '';

            while (! str_contains($output, "\n") && microtime(true) < $deadline) {
                usleep(10_000);
                $output .= $listener->getIncrementalOutput();
            }

            $this->config->ssrUrl = 'http://' . trim($output);

            $exit = $this->start();

            $deadline = microtime(true) + 10.0;
            $request  = '';

            while (! str_contains($request, "\n") && microtime(true) < $deadline) {
                usleep(10_000);
                $request .= $listener->getIncrementalOutput();
            }
        } finally {
            $listener->stop(0);
        }

        $this->assertSame(0, $exit);
        $this->assertSame('GET /shutdown HTTP/1.1', trim($request));
        $this->assertSame(['stop', 'start'], $this->events);
        $this->assertStringNotContainsString('Inertia SSR server stopped.', $this->getStreamFilterBuffer());
    }

    public function testNothingIsPrintedAndTheProcessStillStartsWhenNoServerIsListening(): void
    {
        $this->realStop       = true;
        $this->config->ssrUrl = $this->closedPortUrl();

        $exit = $this->start();

        $this->assertSame(0, $exit);
        $this->assertNotNull($this->requested);
        $this->assertStringNotContainsString('Unable to connect', $this->getStreamFilterBuffer());
        $this->assertStringNotContainsString('not running', $this->getStreamFilterBuffer());
        $this->assertStringNotContainsString('stopped', $this->getStreamFilterBuffer());
    }

    #[DataProvider('provideTheRuntimeOptionIsReadFromTheCommandLine')]
    public function testTheRuntimeOptionIsReadFromTheCommandLine(string $arguments, string $runtime): void
    {
        $this->config->ssrRuntime = 'node';

        command('inertia:start-ssr ' . $arguments);

        $this->assertSame([$runtime, $this->dir . '/root/bootstrap/ssr/ssr.mjs'], $this->requested);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTheRuntimeOptionIsReadFromTheCommandLine(): iterable
    {
        yield 'separate value' => ['--runtime bun', 'bun'];

        yield 'equals sign' => ['--runtime=bun', 'bun'];

        yield 'quoted value with a space' => ['"--runtime=/opt/node bin"', '/opt/node bin'];

        yield 'no option' => ['', 'node'];

        yield 'empty value after an equals sign' => ['--runtime=', 'node'];

        yield 'option without a value' => ['--runtime', 'node'];

        yield '--runtime ""' => ['--runtime ""', 'node'];

        yield 'positional argument before the option' => ['stray --runtime bun', 'bun'];

        yield 'positional argument alone' => ['stray', 'node'];
    }

    public function testTheHelpShowsTheOptionTheParserUnderstands(): void
    {
        Services::reset(true);

        command('help inertia:start-ssr');

        $this->assertStringContainsString('inertia:start-ssr [--runtime <runtime>]', $this->getStreamFilterBuffer());
    }

    public function testAnEmptyConfiguredBundleIsTreatedAsNoneConfigured(): void
    {
        unlink($this->dir . '/root/bootstrap/ssr/ssr.mjs');
        $this->config->ssrBundle = '';

        $exit = $this->start();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Set the correct Inertia SSR bundle path', $this->getStreamFilterBuffer());
        $this->assertStringNotContainsString('configured path', $this->getStreamFilterBuffer());
    }

    public function testTheCommandsAreListedUnderTheInertiaGroup(): void
    {
        Services::reset(true);

        $commands = service('commands')->getCommands();

        foreach (['inertia:start-ssr', 'inertia:stop-ssr', 'inertia:check-ssr'] as $name) {
            $this->assertArrayHasKey($name, $commands);
            $this->assertSame('Inertia', $commands[$name]['group']);
        }
    }

    /**
     * @param array<string, string|null> $params
     */
    private function start(array $params = []): int
    {
        return $this->commandUnderTest()->run($params);
    }

    /**
     * @internal called by the command line in place of the command it would create
     */
    public function commandUnderTest(): StartSsr
    {
        return new class (service('logger'), service('commands'), $this) extends StartSsr {
            public function __construct($logger, $commands, private readonly StartSsrTest $test)
            {
                parent::__construct($logger, $commands);
            }

            protected function makeProcess(array $command): Process
            {
                return $this->test->handOut($command);
            }

            protected function bundleDetector(InertiaConfig $config): BundleDetector
            {
                return $this->test->detector($config);
            }

            protected function stopOnSignals(Process $process): void
            {
            }

            protected function stopRunningServer(): void
            {
                if ($this->test->stopRequested()) {
                    parent::stopRunningServer();
                }
            }
        };
    }

    /**
     * @internal called by the command under test when it would stop a running server
     */
    public function stopRequested(): bool
    {
        $this->events[] = 'stop';

        return $this->realStop;
    }

    /**
     * @param list<string> $command
     *
     * @internal called by the command under test in place of the process it would start
     */
    public function handOut(array $command): Process
    {
        $this->requested = $command;
        $this->events[]  = 'start';
        $this->started   = $this->stand;

        return $this->stand;
    }

    /**
     * @internal called by the command under test in place of its default bundle locations
     */
    public function detector(InertiaConfig $config): BundleDetector
    {
        return new BundleDetector($config, $this->dir . '/root/', $this->dir . '/public/');
    }

    private function standIn(string $code): Process
    {
        return new Process([PHP_BINARY, '-r', $code]);
    }

    private function assertNotLogged(string $level, string $message): void
    {
        $this->assertFalse(TestLogger::didLog($level, $message));
    }

    private function closedPortUrl(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name   = stream_socket_get_name($socket, false);
        fclose($socket);

        return 'http://' . $name;
    }
}
