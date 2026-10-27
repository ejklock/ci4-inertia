<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Commands;

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use Ejklock\Ci4Inertia\Commands\StopSsr;
use Ejklock\Ci4Inertia\Config\Inertia as InertiaConfig;
use Ejklock\Ci4Inertia\Ssr\BundleDetector;
use Ejklock\Ci4Inertia\Ssr\Gateway;
use Ejklock\Ci4Inertia\Ssr\HttpGateway;
use Ejklock\Ci4Inertia\Ssr\Response;
use ReflectionClassConstant;
use Symfony\Component\Process\Process;

/**
 * Verifies `spark inertia:stop-ssr` against real loopback listeners run in child processes: one
 * that hangs up without replying, one that answers HTTP and a closed port.
 *
 * @internal
 */
final class StopSsrTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    /**
     * Accepts connections, reads the request head, prints its request line and then either answers
     * or closes the connection without a reply, as the first argument says.
     */
    private const LISTENER = <<<'PHP'
        $server = stream_socket_server('tcp://127.0.0.1:0');
        echo stream_socket_get_name($server, false), "\n";
        while ($client = @stream_socket_accept($server, 30)) {
            $head = '';
            while (! str_contains($head, "\r\n\r\n")) {
                $chunk = fread($client, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $head .= $chunk;
            }
            echo strtok($head, "\r\n"), "\n";
            if ($argv[1] === 'hold') {
                sleep(9);
            }
            if ($argv[1] === 'answer') {
                fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
            }
            fclose($client);
        }
        PHP;

    /**
     * @var list<Process>
     */
    private array $listeners = [];

    /**
     * @var list<resource>
     */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            fclose($socket);
        }

        $this->sockets = [];

        foreach ($this->listeners as $listener) {
            $listener->stop(0);
        }

        $this->listeners = [];

        Services::reset(true);
        Factories::reset('config');

        parent::tearDown();
    }

    public function testAServerThatHangsUpWithoutAReplyIsReportedAsStopped(): void
    {
        $listener = $this->listen('hang-up');

        $exit = $this->stop($this->gatewayFor($listener['url']));

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Inertia SSR server stopped.', $this->getStreamFilterBuffer());
        $this->assertSame('GET /shutdown HTTP/1.1', $this->requestLine($listener['process']));
    }

    public function testTheShutdownRequestReturnsTheCurlErrnoWithoutPrinting(): void
    {
        $listener = $this->listen('hang-up');
        Services::injectMock('inertiaSsrGateway', $this->gatewayFor($listener['url']));

        $this->assertSame(CURLE_GOT_NOTHING, StopSsr::requestShutdown());
        $this->assertSame('GET /shutdown HTTP/1.1', $this->requestLine($listener['process']));

        Services::injectMock('inertiaSsrGateway', $this->gatewayFor($this->closedPortUrl()));

        $this->assertSame(CURLE_COULDNT_CONNECT, StopSsr::requestShutdown());
        $this->assertSame('', $this->getStreamFilterBuffer());
    }

    public function testTheShutdownRequestGivesUpWithinTwoSecondsToConnectAndFiveInTotal(): void
    {
        $this->assertSame(2, (new ReflectionClassConstant(StopSsr::class, 'CONNECT_TIMEOUT'))->getValue());
        $this->assertSame(5, (new ReflectionClassConstant(StopSsr::class, 'TIMEOUT'))->getValue());
    }

    public function testAServerThatNeverAcceptsTheConnectionIsAnErrorWithinTheConnectBound(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['socket' => ['backlog' => 1]]));
        $this->assertIsResource($server);
        $this->sockets[] = $server;
        $name            = stream_socket_get_name($server, false);

        for ($i = 0; $i < 4; $i++) {
            $client = stream_socket_client('tcp://' . $name, $code, $message, 1, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);

            if (is_resource($client)) {
                $this->sockets[] = $client;
            }
        }

        $started = microtime(true);
        $exit    = $this->stop($this->gatewayWithoutProductionUrl('http://' . $name));
        $elapsed = microtime(true) - $started;

        $this->assertSame(1, $exit);
        $this->assertLessThan(4.0, $elapsed);
    }

    public function testAServerThatNeverRepliesIsAnErrorWithinAShortBound(): void
    {
        $listener = $this->listen('hold');
        Services::injectMock('inertiaSsrGateway', $this->gatewayWithoutProductionUrl($listener['url']));

        $command = new class (service('logger'), service('commands')) extends StopSsr {
            protected const TIMEOUT = 1;
        };

        $started = microtime(true);
        $exit    = $command->run([]);
        $elapsed = microtime(true) - $started;

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unable to connect to Inertia SSR server.', $this->getStreamFilterBuffer());
        $this->assertLessThan(8.0, $elapsed);
    }

    public function testAServerThatNeverRepliesIsNotRunningWithTheGracefulOption(): void
    {
        $listener = $this->listen('hold');
        Services::injectMock('inertiaSsrGateway', $this->gatewayWithoutProductionUrl($listener['url']));

        $command = new class (service('logger'), service('commands')) extends StopSsr {
            protected const TIMEOUT = 1;
        };

        $started = microtime(true);
        $exit    = $command->run(['graceful' => null]);
        $elapsed = microtime(true) - $started;

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Inertia SSR server is not running.', $this->getStreamFilterBuffer());
        $this->assertLessThan(8.0, $elapsed);
    }

    public function testAClosedPortIsAnErrorWithoutTheGracefulOption(): void
    {
        $exit = $this->stop($this->gatewayFor($this->closedPortUrl()));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unable to connect to Inertia SSR server.', $this->getStreamFilterBuffer());
    }

    public function testAClosedPortIsSuccessfulWithTheGracefulOption(): void
    {
        $exit = $this->stop($this->gatewayFor($this->closedPortUrl()), ['graceful' => null]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Inertia SSR server is not running.', $this->getStreamFilterBuffer());
        $this->assertStringNotContainsString('Unable to connect', $this->getStreamFilterBuffer());
    }

    public function testAServerThatAnswersHttpIsAnErrorEvenWithTheGracefulOption(): void
    {
        $listener = $this->listen('answer');

        $exit = $this->stop($this->gatewayFor($listener['url']), ['graceful' => null]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unable to connect to Inertia SSR server.', $this->getStreamFilterBuffer());
        $this->assertSame('GET /shutdown HTTP/1.1', $this->requestLine($listener['process']));
    }

    public function testASubclassOverridingTheProductionUrlIsHonored(): void
    {
        $listener = $this->listen('hang-up');
        $base     = $listener['url'];
        $config   = new InertiaConfig();
        $gateway  = new class ($config, $base) extends HttpGateway {
            public function __construct(InertiaConfig $config, private readonly string $base)
            {
                parent::__construct($config, static fn (array $options) => service('curlrequest', $options, null, false), new BundleDetector($config));
            }

            public function getProductionUrl(string $path = '/'): string
            {
                return $this->base . '/custom' . $path;
            }
        };

        $exit = $this->stop($gateway);

        $this->assertSame(0, $exit);
        $this->assertSame('GET /custom/shutdown HTTP/1.1', $this->requestLine($listener['process']));
    }

    public function testAGatewayThatIsNotHttpUsesTheConfiguredUrl(): void
    {
        $listener                 = $this->listen('hang-up');
        config('Inertia')->ssrUrl = $listener['url'] . '/';

        $exit = $this->stop(new class () implements Gateway {
            public function dispatch(array $page): ?Response
            {
                return null;
            }
        });

        $this->assertSame(0, $exit);
        $this->assertSame('GET /shutdown HTTP/1.1', $this->requestLine($listener['process']));
    }

    /**
     * @param array<string, string|null> $params
     */
    private function stop(Gateway $gateway, array $params = []): int
    {
        Services::injectMock('inertiaSsrGateway', $gateway);

        return service('commands')->run('inertia:stop-ssr', $params);
    }

    /**
     * A gateway that is not an HTTP one, with the server URL in the config, so the shutdown URL
     * comes from the configured setting.
     */
    private function gatewayWithoutProductionUrl(string $url): Gateway
    {
        config('Inertia')->ssrUrl = $url;

        return new class () implements Gateway {
            public function dispatch(array $page): ?Response
            {
                return null;
            }
        };
    }

    private function gatewayFor(string $url): HttpGateway
    {
        $config         = new InertiaConfig();
        $config->ssrUrl = $url;

        return new HttpGateway($config, static fn (array $options) => service('curlrequest', $options, null, false), new BundleDetector($config));
    }

    /**
     * @return array{url: string, process: Process}
     */
    private function listen(string $mode): array
    {
        $process = new Process([PHP_BINARY, '-r', self::LISTENER, $mode]);
        $process->start();
        $this->listeners[] = $process;

        $deadline = microtime(true) + 10.0;
        $output   = '';

        while (! str_contains($output, "\n") && microtime(true) < $deadline) {
            usleep(10_000);
            $output .= $process->getIncrementalOutput();
        }

        $this->assertStringContainsString("\n", $output, 'the listener did not report its address: ' . $process->getErrorOutput());

        return ['url' => 'http://' . trim($output), 'process' => $process];
    }

    private function requestLine(Process $process): string
    {
        $deadline = microtime(true) + 10.0;
        $output   = '';

        while (! str_contains($output, "\n") && microtime(true) < $deadline) {
            usleep(10_000);
            $output .= $process->getIncrementalOutput();
        }

        return trim($output);
    }

    private function closedPortUrl(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name   = stream_socket_get_name($socket, false);
        fclose($socket);

        return 'http://' . $name;
    }
}
