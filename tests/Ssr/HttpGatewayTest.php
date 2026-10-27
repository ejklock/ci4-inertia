<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Ssr;

use Closure;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCURLRequest;
use Config\App as AppConfig;
use Config\Services;
use Ejklock\Ci4Inertia\Config\Inertia as InertiaConfig;
use Ejklock\Ci4Inertia\Ssr\BundleDetector;
use Ejklock\Ci4Inertia\Ssr\Gateway;
use Ejklock\Ci4Inertia\Ssr\HttpGateway;
use Ejklock\Ci4Inertia\Ssr\Response;
use Ejklock\Ci4Inertia\Ssr\SsrErrorType;
use Ejklock\Ci4Inertia\Ssr\SsrException;
use Ejklock\Ci4Inertia\Ssr\SsrRenderFailed;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Verifies the SSR gateway against a mocked HTTP client and temporary bundle and hot files:
 * the rendered response, the cases where nothing is sent, hot mode, failure reporting and the
 * config defaults and service wiring.
 *
 * @internal
 */
final class HttpGatewayTest extends CIUnitTestCase
{
    private string $dir;
    private InertiaConfig $config;
    private MockCURLRequest $client;
    private string $output             = '';
    private ?RuntimeException $failure = null;
    private int $sent                  = 0;

    /**
     * @var list<SsrRenderFailed>
     */
    private array $failures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = rtrim(sys_get_temp_dir(), '/') . '/ssr-gateway-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/root/bootstrap/ssr', 0o777, true);
        mkdir($this->dir . '/public/js', 0o777, true);

        $this->config                        = new InertiaConfig();
        $this->config->ssrHotFile            = $this->dir . '/hot';
        $this->config->ssrBundle             = null;
        $this->config->ssrEnsureBundleExists = true;
        $this->failures                      = [];

        touch($this->dir . '/root/bootstrap/ssr/ssr.mjs');

        Events::removeAllListeners();
        Events::on(SsrRenderFailed::class, function (SsrRenderFailed $event): void {
            $this->failures[] = $event;
        });

        $this->output  = "HTTP/1.1 200 OK\r\n\r\n{}";
        $this->failure = null;
        $this->sent    = 0;
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners();
        Services::reset(true);

        foreach (array_merge(
            glob($this->dir . '/root/bootstrap/ssr/*') ?: [],
            glob($this->dir . '/public/js/*') ?: [],
            glob($this->dir . '/*') ?: [],
        ) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach ([$this->dir . '/root/bootstrap/ssr', $this->dir . '/root/bootstrap', $this->dir . '/root', $this->dir . '/public/js', $this->dir . '/public', $this->dir] as $path) {
            if (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function makeClient(array $options): MockCURLRequest
    {
        $config   = config(AppConfig::class);
        $response = service('response', null, false);
        $onSend   = function (): void {
            $this->sent++;
        };

        $client = new class ($config, new URI(), $response, $options, $this->failure, $onSend) extends MockCURLRequest {
            /**
             * @param array<string, mixed> $options
             */
            public function __construct(AppConfig $config, URI $uri, ResponseInterface $response, array $options, private readonly ?RuntimeException $failure, private readonly Closure $onSend)
            {
                parent::__construct($config, $uri, $response, $options);
            }

            protected function sendRequest(array $curlOptions = []): string
            {
                ($this->onSend)();

                if ($this->failure instanceof RuntimeException) {
                    throw $this->failure;
                }

                return parent::sendRequest($curlOptions);
            }
        };

        $this->client = $client;

        return $client->setOutput($this->output);
    }

    private function answer(string $status, string $body): void
    {
        $this->output = 'HTTP/1.1 ' . $status . "\r\n\r\n" . $body;
    }

    private function gateway(): Gateway
    {
        return new HttpGateway(
            $this->config,
            fn (array $options): CURLRequest => $this->makeClient($options),
            new BundleDetector($this->config, $this->dir . '/root/', $this->dir . '/public/'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function page(): array
    {
        return ['component' => 'Users/Index', 'props' => ['a' => 1], 'url' => '/users', 'version' => 'v1'];
    }

    private function assertResponse(string $head, string $body, ?Response $actual): void
    {
        $this->assertInstanceOf(Response::class, $actual);
        $this->assertSame($head, $actual->head);
        $this->assertSame($body, $actual->body);
    }

    private function productionClient(): CURLRequest
    {
        $config             = config('Inertia');
        $config->ssrTimeout = $this->config->ssrTimeout;
        $gateway            = service('inertiaSsrGateway', false);
        $method             = new ReflectionMethod($gateway, 'pendingRequest');

        $client             = $method->invoke($gateway);
        $config->ssrTimeout = null;
        $this->assertInstanceOf(CURLRequest::class, $client);

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function clientConfig(CURLRequest $client): array
    {
        $property = new ReflectionProperty(CURLRequest::class, 'config');

        return $property->getValue($client);
    }

    private function detector(): BundleDetector
    {
        return new BundleDetector($this->config, $this->dir . '/root/', $this->dir . '/public/');
    }

    public function testRendersHeadAndBodyFromAHealthyServer(): void
    {
        $this->answer('200 OK', '{"head":["<title>A</title>","<meta>"],"body":"<div>x</div>"}');

        $response = $this->gateway()->dispatch($this->page());

        $this->assertResponse("<title>A</title>\n<meta>", '<div>x</div>', $response);
        $this->assertSame([], $this->failures);
    }

    public function testPostsThePageAsJsonToTheRenderUrl(): void
    {
        $this->answer('200 OK', '{"body":"b"}');

        $this->gateway()->dispatch($this->page());

        $this->assertSame('http://127.0.0.1:13714/render', $this->client->curl_options[CURLOPT_URL]);
        $this->assertSame(json_encode($this->page()), $this->client->curl_options[CURLOPT_POSTFIELDS]);
        $this->assertSame('POST', $this->client->curl_options[CURLOPT_CUSTOMREQUEST]);
        $this->assertContains('Content-Type: application/json', $this->client->curl_options[CURLOPT_HTTPHEADER]);
    }

    public function testDoesNotDoubleTheSlashOfAConfiguredUrl(): void
    {
        $this->config->ssrUrl = 'http://ssr.test:9000/';
        $this->answer('200 OK', '{"body":"b"}');

        $this->gateway()->dispatch($this->page());

        $this->assertSame('http://ssr.test:9000/render', $this->client->curl_options[CURLOPT_URL]);
    }

    public function testAnAnswerWithoutHeadHasAnEmptyHead(): void
    {
        $this->answer('200 OK', '{"body":"b"}');

        $this->assertResponse('', 'b', $this->gateway()->dispatch($this->page()));
    }

    public function testAnAnswerWithoutBodyHasAnEmptyBody(): void
    {
        $this->answer('200 OK', '{"head":["h"]}');

        $this->assertResponse('h', '', $this->gateway()->dispatch($this->page()));
    }

    public function testAnEmptySuccessBodyFallsBackWithoutAnEvent(): void
    {
        $this->answer('200 OK', '');

        $this->assertNotInstanceOf(Response::class, $this->gateway()->dispatch($this->page()));
        $this->assertSame([], $this->failures);
        $this->assertSame(1, $this->sent);
    }

    public function testANonJsonSuccessBodyFallsBackWithoutAnEvent(): void
    {
        $this->answer('200 OK', '<html>nope</html>');

        $this->assertNotInstanceOf(Response::class, $this->gateway()->dispatch($this->page()));
        $this->assertSame([], $this->failures);
    }

    public function testTheConfiguredTimeoutIsSentInSeconds(): void
    {
        $this->config->ssrTimeout = 3;
        $this->answer('200 OK', '{"body":"b"}');

        $this->gateway()->dispatch($this->page());

        $this->assertEqualsWithDelta(3000, $this->client->curl_options[CURLOPT_TIMEOUT_MS], 0.001);
    }

    public function testWithoutAConfiguredTimeoutTheRequestGivesUpAfterThirtySecondsAndConnectsWithinTen(): void
    {
        $this->answer('200 OK', '{"body":"b"}');

        $this->gateway()->dispatch($this->page());

        $this->assertEqualsWithDelta(30000, $this->client->curl_options[CURLOPT_TIMEOUT_MS], 0.001);
        $this->assertEqualsWithDelta(10000, $this->client->curl_options[CURLOPT_CONNECTTIMEOUT_MS], 0.001);
    }

    public function testAZeroTimeoutIsTreatedAsUnset(): void
    {
        $this->config->ssrTimeout = 0;
        $this->answer('200 OK', '{"body":"b"}');

        $this->gateway()->dispatch($this->page());

        $this->assertEqualsWithDelta(30000, $this->client->curl_options[CURLOPT_TIMEOUT_MS], 0.001);
        $this->assertEqualsWithDelta(10000, $this->client->curl_options[CURLOPT_CONNECTTIMEOUT_MS], 0.001);
    }

    public function testAConfiguredTimeoutKeepsTheTenSecondConnectLimit(): void
    {
        $this->config->ssrTimeout = 3;
        $this->answer('200 OK', '{"body":"b"}');

        $this->gateway()->dispatch($this->page());

        $this->assertEqualsWithDelta(3000, $this->client->curl_options[CURLOPT_TIMEOUT_MS], 0.001);
        $this->assertEqualsWithDelta(10000, $this->client->curl_options[CURLOPT_CONNECTTIMEOUT_MS], 0.001);
    }

    public function testAnErrorStatusIsAnsweredWithoutTheClientThrowingOnIt(): void
    {
        $this->answer('200 OK', '{"body":"b"}');

        $this->gateway()->dispatch($this->page());

        $this->assertFalse($this->client->curl_options[CURLOPT_FAILONERROR]);
    }

    public function testAClientErrorStatusIsAFailure(): void
    {
        $this->answer('400 Bad Request', '{"error":"bad page"}');

        $this->assertNotInstanceOf(Response::class, $this->gateway()->dispatch($this->page()));
        $this->assertCount(1, $this->failures);
        $this->assertSame('bad page', $this->failures[0]->error);
    }

    public function testEachDispatchBuildsAFreshClient(): void
    {
        $this->answer('200 OK', '{"body":"b"}');
        $gateway = $this->gateway();

        $gateway->dispatch($this->page());
        $first = $this->client;
        $gateway->dispatch($this->page());

        $this->assertNotSame($first, $this->client);
    }

    public function testTheServiceNeverUsesTheSharedHttpClient(): void
    {
        $shared = new MockCURLRequest(config(AppConfig::class), new URI('http://shared.test'), service('response', null, false), ['headers' => ['X-Shared' => '1'], 'timeout' => 99]);
        Services::injectMock('curlrequest', $shared);
        $this->config->ssrTimeout = 7;

        $first  = $this->productionClient();
        $second = $this->productionClient();

        $this->assertNotSame($shared, $first);
        $this->assertNotSame($first, $second);
        $this->assertEqualsWithDelta(7.0, $this->clientConfig($first)['timeout'], 0.001);
        $this->assertEqualsWithDelta(10, $this->clientConfig($first)['connect_timeout'], 0.001);
        $this->assertArrayNotHasKey('X-Shared', $this->clientConfig($first)['headers'] ?? []);
    }

    public function testSendsNothingWhenSsrIsDisabled(): void
    {
        $this->config->ssrEnabled = false;

        $this->assertNotInstanceOf(Response::class, $this->gateway()->dispatch($this->page()));
        $this->assertSame(0, $this->sent);
    }

    public function testSendsNothingWhenTheBundleIsMissing(): void
    {
        unlink($this->dir . '/root/bootstrap/ssr/ssr.mjs');

        $this->assertNotInstanceOf(Response::class, $this->gateway()->dispatch($this->page()));
        $this->assertSame(0, $this->sent);
    }

    public function testSendsTheRequestWithoutABundleWhenTheCheckIsOff(): void
    {
        unlink($this->dir . '/root/bootstrap/ssr/ssr.mjs');
        $this->config->ssrEnsureBundleExists = false;
        $this->answer('200 OK', '{"body":"b"}');

        $this->assertResponse('', 'b', $this->gateway()->dispatch($this->page()));
        $this->assertSame(1, $this->sent);
    }

    public function testTheConfiguredBundleWinsOverTheDefaults(): void
    {
        touch($this->dir . '/custom.js');
        $this->config->ssrBundle = $this->dir . '/custom.js';

        $this->assertSame($this->dir . '/custom.js', $this->detector()->detect());
    }

    public function testAMissingConfiguredBundleFallsThroughToTheDefaults(): void
    {
        $this->config->ssrBundle = $this->dir . '/missing.js';

        $this->assertSame($this->dir . '/root/bootstrap/ssr/ssr.mjs', $this->detector()->detect());
    }

    public function testDefaultBundlesAreFoundInTheDocumentedOrder(): void
    {
        $this->assertSame($this->dir . '/root/bootstrap/ssr/ssr.mjs', $this->detector()->detect());

        touch($this->dir . '/public/js/app.js');
        $this->assertSame($this->dir . '/root/bootstrap/ssr/ssr.mjs', $this->detector()->detect());

        unlink($this->dir . '/root/bootstrap/ssr/ssr.mjs');
        $this->assertSame($this->dir . '/public/js/app.js', $this->detector()->detect());

        touch($this->dir . '/public/js/ssr.js');
        $this->assertSame($this->dir . '/public/js/ssr.js', $this->detector()->detect());

        touch($this->dir . '/root/bootstrap/ssr/app.mjs');
        $this->assertSame($this->dir . '/root/bootstrap/ssr/app.mjs', $this->detector()->detect());

        touch($this->dir . '/root/bootstrap/ssr/app.js');
        $this->assertSame($this->dir . '/root/bootstrap/ssr/app.js', $this->detector()->detect());

        touch($this->dir . '/root/bootstrap/ssr/ssr.js');
        $this->assertSame($this->dir . '/root/bootstrap/ssr/ssr.js', $this->detector()->detect());

        foreach (glob($this->dir . '/root/bootstrap/ssr/*') ?: [] as $path) {
            unlink($path);
        }

        unlink($this->dir . '/public/js/ssr.js');
        unlink($this->dir . '/public/js/app.js');
        $this->assertNull($this->detector()->detect());
    }

    public function testHotModeTargetsTheDevServerEvenWithoutABundle(): void
    {
        unlink($this->dir . '/root/bootstrap/ssr/ssr.mjs');
        file_put_contents($this->dir . '/hot', "http://localhost:5173\n");
        $this->answer('200 OK', '{"body":"b"}');

        $this->assertResponse('', 'b', $this->gateway()->dispatch($this->page()));
        $this->assertSame('http://localhost:5173/__inertia_ssr', $this->client->curl_options[CURLOPT_URL]);
    }

    public function testTheConfiguredHotUrlWinsOverTheHotFile(): void
    {
        file_put_contents($this->dir . '/hot', 'http://localhost:5173');
        $this->config->ssrHotUrl = 'http://dev.test:3000/';
        $this->answer('200 OK', '{"body":"b"}');

        $this->gateway()->dispatch($this->page());

        $this->assertSame('http://dev.test:3000/__inertia_ssr', $this->client->curl_options[CURLOPT_URL]);
    }

    public function testWithoutAHotFileTheProductionUrlIsUsed(): void
    {
        $this->answer('200 OK', '{"body":"b"}');

        $this->gateway()->dispatch($this->page());

        $this->assertSame('http://127.0.0.1:13714/render', $this->client->curl_options[CURLOPT_URL]);
    }

    public function testAFailedAnswerFallsBackAndReportsEveryField(): void
    {
        $this->answer('500 Internal Server Error', '{"error":"window is not defined","type":"browser-api","hint":"h","browserApi":"window","stack":"s","sourceLocation":"app.tsx:3"}');

        $this->assertNotInstanceOf(Response::class, $this->gateway()->dispatch($this->page()));

        $this->assertCount(1, $this->failures);
        $event = $this->failures[0];
        $this->assertSame($this->page(), $event->page);
        $this->assertSame('window is not defined', $event->error);
        $this->assertSame(SsrErrorType::BrowserApi, $event->type);
        $this->assertSame('h', $event->hint);
        $this->assertSame('window', $event->browserApi);
        $this->assertSame('s', $event->stack);
        $this->assertSame('app.tsx:3', $event->sourceLocation);
        $this->assertSame('Users/Index', $event->component());
        $this->assertSame('/users', $event->url());
        $this->assertSame([
            'component'       => 'Users/Index',
            'url'             => '/users',
            'error'           => 'window is not defined',
            'type'            => 'browser-api',
            'hint'            => 'h',
            'browser_api'     => 'window',
            'source_location' => 'app.tsx:3',
        ], $event->toArray());
    }

    public function testAFailedAnswerThatIsNotJsonReportsAnUnknownError(): void
    {
        $this->answer('500 Internal Server Error', 'boom');

        $this->assertNotInstanceOf(Response::class, $this->gateway()->dispatch($this->page()));

        $this->assertCount(1, $this->failures);
        $this->assertSame('Unknown SSR error', $this->failures[0]->error);
        $this->assertSame(SsrErrorType::Unknown, $this->failures[0]->type);
    }

    public function testAnUnrecognisedErrorTypeIsReportedAsUnknown(): void
    {
        $this->answer('500 Internal Server Error', '{"error":"e","type":"weird"}');

        $this->gateway()->dispatch($this->page());

        $this->assertSame(SsrErrorType::Unknown, $this->failures[0]->type);
    }

    public function testAnEventWithoutOptionalFieldsOmitsThemFromItsArray(): void
    {
        $this->answer('500 Internal Server Error', '{"error":"e"}');

        $this->gateway()->dispatch(['component' => 'X']);

        $this->assertSame(['component' => 'X', 'url' => '/', 'error' => 'e', 'type' => 'unknown'], $this->failures[0]->toArray());
    }

    public function testATransportExceptionFallsBackAndIsReportedAsAConnectionError(): void
    {
        $this->failure = new RuntimeException('Connection refused');

        $this->assertNotInstanceOf(Response::class, $this->gateway()->dispatch($this->page()));

        $this->assertCount(1, $this->failures);
        $this->assertSame('Connection refused', $this->failures[0]->error);
        $this->assertSame(SsrErrorType::Connection, $this->failures[0]->type);
    }

    public function testThrowsAnSsrExceptionForAFailedAnswerWhenConfigured(): void
    {
        $this->config->ssrThrowOnError = true;
        $this->answer('500 Internal Server Error', '{"error":"window is not defined","type":"browser-api","hint":"h","sourceLocation":"app.tsx:3"}');

        try {
            $this->gateway()->dispatch(['component' => 'X', 'url' => '/x']);
            $this->fail('No exception was thrown');
        } catch (SsrException $e) {
            $this->assertSame('SSR render failed for component [X]: window is not defined at app.tsx:3', $e->getMessage());
            $this->assertSame($this->failures[0], $e->event);
            $this->assertSame('X', $e->component());
            $this->assertSame(SsrErrorType::BrowserApi, $e->type());
            $this->assertSame('h', $e->hint());
            $this->assertSame('app.tsx:3', $e->sourceLocation());
        }
    }

    public function testTheMessageOmitsTheLocationWhenThereIsNone(): void
    {
        $this->config->ssrThrowOnError = true;
        $this->answer('500 Internal Server Error', '{"error":"bad"}');

        try {
            $this->gateway()->dispatch(['component' => 'X']);
            $this->fail('No exception was thrown');
        } catch (SsrException $e) {
            $this->assertSame('SSR render failed for component [X]: bad', $e->getMessage());
        }
    }

    public function testThrowsAnSsrExceptionForATransportFailureWhenConfigured(): void
    {
        $this->config->ssrThrowOnError = true;
        $this->failure                 = new RuntimeException('Connection refused');

        try {
            $this->gateway()->dispatch($this->page());
            $this->fail('No exception was thrown');
        } catch (SsrException $e) {
            $this->assertSame('SSR render failed for component [Users/Index]: Connection refused', $e->getMessage());
            $this->assertSame(SsrErrorType::Connection, $e->type());
        }
    }

    public function testAPageWithoutComponentOrUrlUsesTheDefaults(): void
    {
        $event = new SsrRenderFailed([], 'e');

        $this->assertSame('Unknown', $event->component());
        $this->assertSame('/', $event->url());
        $this->assertSame(SsrErrorType::Unknown, $event->type);
    }

    public function testTheErrorTypeFromAMissingStringIsUnknown(): void
    {
        $this->assertSame(SsrErrorType::Unknown, SsrErrorType::fromString(null));
        $this->assertSame(SsrErrorType::Render, SsrErrorType::fromString('render'));
    }

    public function testTheConfigHoldsTheLaravelDefaults(): void
    {
        $config = new InertiaConfig();

        $this->assertTrue($config->ssrEnabled);
        $this->assertSame('http://127.0.0.1:13714', $config->ssrUrl);
        $this->assertNull($config->ssrHotUrl);
        $this->assertSame(FCPATH . 'hot', $config->ssrHotFile);
        $this->assertNull($config->ssrTimeout);
        $this->assertTrue($config->ssrEnsureBundleExists);
        $this->assertNull($config->ssrBundle);
        $this->assertFalse($config->ssrThrowOnError);
    }

    public function testASubclassCanRedirectAHotDispatchThroughTheHotUrlHook(): void
    {
        file_put_contents($this->dir . '/hot', 'http://localhost:5173');
        $this->answer('200 OK', '{"body":"b"}');
        $gateway = new class ($this->config, fn (array $options): CURLRequest => $this->makeClient($options), $this->detector()) extends HttpGateway {
            protected function getHotUrl(string $path = '/'): string
            {
                return 'http://hot.override.test' . $path;
            }
        };

        $gateway->dispatch($this->page());

        $this->assertSame('http://hot.override.test/__inertia_ssr', $this->client->curl_options[CURLOPT_URL]);
    }

    public function testASubclassSeesAFailureThroughTheFailureHook(): void
    {
        $this->answer('500 Internal Server Error', '{"error":"bad"}');
        $gateway = new class ($this->config, fn (array $options): CURLRequest => $this->makeClient($options), $this->detector()) extends HttpGateway {
            /**
             * @var list<array<string, mixed>|null>
             */
            public array $seen = [];

            protected function handleSsrFailure(array $page, ?array $error): void
            {
                $this->seen[] = $error;
            }
        };

        $gateway->dispatch($this->page());

        $this->assertSame([['error' => 'bad']], $gateway->seen);
    }

    public function testASubclassCanSwitchSsrOffThroughTheEnabledHook(): void
    {
        $gateway = new class ($this->config, fn (array $options): CURLRequest => $this->makeClient($options), $this->detector()) extends HttpGateway {
            protected function ssrIsEnabled(): bool
            {
                return false;
            }
        };

        $this->assertNotInstanceOf(Response::class, $gateway->dispatch($this->page()));
        $this->assertSame(0, $this->sent);
    }

    public function testASubclassCanSkipTheBundleCheckThroughTheEnsureHook(): void
    {
        unlink($this->dir . '/root/bootstrap/ssr/ssr.mjs');
        $this->answer('200 OK', '{"body":"b"}');
        $gateway = new class ($this->config, fn (array $options): CURLRequest => $this->makeClient($options), $this->detector()) extends HttpGateway {
            protected function shouldEnsureBundleExists(): bool
            {
                return false;
            }
        };

        $this->assertResponse('', 'b', $gateway->dispatch($this->page()));
    }

    public function testASubclassCanReportABundleThroughTheBundleHook(): void
    {
        unlink($this->dir . '/root/bootstrap/ssr/ssr.mjs');
        $this->answer('200 OK', '{"body":"b"}');
        $gateway = new class ($this->config, fn (array $options): CURLRequest => $this->makeClient($options), $this->detector()) extends HttpGateway {
            protected function bundleExists(): bool
            {
                return true;
            }
        };

        $this->assertResponse('', 'b', $gateway->dispatch($this->page()));
    }

    public function testASubclassCanConfigureTheClientThroughTheRequestHook(): void
    {
        $this->answer('200 OK', '{"body":"b"}');
        $gateway = new class ($this->config, fn (array $options): CURLRequest => $this->makeClient($options), $this->detector()) extends HttpGateway {
            protected function pendingRequest(): CURLRequest
            {
                return ($this->clientFactory)(['http_errors' => false, 'timeout' => 5]);
            }
        };

        $gateway->dispatch($this->page());

        $this->assertEqualsWithDelta(5000, $this->client->curl_options[CURLOPT_TIMEOUT_MS], 0.001);
    }

    public function testTheProductionUrlIsPublicAndAppendsThePath(): void
    {
        $gateway = new HttpGateway($this->config, fn (array $options): CURLRequest => $this->makeClient($options), $this->detector());

        $this->assertSame('http://127.0.0.1:13714/health', $gateway->getProductionUrl('/health'));
    }

    public function testAnSsrExceptionCanBeBuiltWithoutAnEvent(): void
    {
        $exception = new SsrException('boom');

        $this->assertSame('boom', $exception->getMessage());
        $this->assertNull($exception->event);
        $this->assertNull($exception->component());
        $this->assertNull($exception->type());
        $this->assertNull($exception->hint());
        $this->assertNull($exception->sourceLocation());
    }

    public function testAnSsrExceptionFromAnEventKeepsItsMessageAndEvent(): void
    {
        $event     = new SsrRenderFailed(['component' => 'X'], 'bad', SsrErrorType::Render, sourceLocation: 'a.tsx:1');
        $exception = SsrException::fromEvent($event);

        $this->assertSame('SSR render failed for component [X]: bad at a.tsx:1', $exception->getMessage());
        $this->assertSame($event, $exception->event);
    }

    public function testTheServiceIsASharedHttpGateway(): void
    {
        $gateway = service('inertiaSsrGateway');

        $this->assertInstanceOf(HttpGateway::class, $gateway);
        $this->assertSame($gateway, service('inertiaSsrGateway'));
        $this->assertNotSame($gateway, service('inertiaSsrGateway', false));
    }
}
