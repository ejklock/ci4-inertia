<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Ssr;

use ArrayObject;
use Closure;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCURLRequest;
use Config\App as AppConfig;
use Config\Services;
use Ejklock\Ci4Inertia\Config\Inertia as InertiaConfig;
use Ejklock\Ci4Inertia\Ssr\BundleDetector;
use Ejklock\Ci4Inertia\Ssr\ConfiguresSsrRequests;
use Ejklock\Ci4Inertia\Ssr\DisablesSsr;
use Ejklock\Ci4Inertia\Ssr\ExcludesSsrPaths;
use Ejklock\Ci4Inertia\Ssr\Gateway;
use Ejklock\Ci4Inertia\Ssr\HttpGateway;
use Ejklock\Ci4Inertia\Ssr\Response;
use Ejklock\Ci4Inertia\Ssr\SsrErrorType;
use Ejklock\Ci4Inertia\Ssr\SsrException;
use Ejklock\Ci4Inertia\Ssr\SsrRenderFailed;
use PHPUnit\Framework\Attributes\DataProvider;
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
            protected function ssrIsEnabled(IncomingRequest $request): bool
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

    private function httpGateway(): HttpGateway
    {
        $gateway = $this->gateway();
        $this->assertInstanceOf(HttpGateway::class, $gateway);

        return $gateway;
    }

    private function requestFor(string $route, string $baseUrl = 'http://example.com/', ?string $requestUri = null, string $indexPage = ''): IncomingRequest
    {
        $config            = new AppConfig();
        $config->baseURL   = $baseUrl;
        $config->indexPage = $indexPage;

        return new class ($config, new SiteURI($config, $route), null, new UserAgent(), $requestUri) extends IncomingRequest {
            public function __construct(AppConfig $config, URI $uri, ?string $body, UserAgent $userAgent, private readonly ?string $requestUri)
            {
                parent::__construct($config, $uri, $body, $userAgent);
            }

            public function getServer($index = null, $filter = null, $flags = null)
            {
                return $index === 'REQUEST_URI' ? $this->requestUri : parent::getServer($index, $filter, $flags);
            }
        };
    }

    private function dispatchFor(HttpGateway $gateway, string $route, string $baseUrl = 'http://example.com/', ?string $requestUri = null, string $indexPage = ''): void
    {
        $this->answer('200 OK', '{"body":"b"}');
        $gateway->dispatch($this->page(), $this->requestFor($route, $baseUrl, $requestUri, $indexPage));
    }

    public function testTheGatewayImplementsTheSsrControlInterfaces(): void
    {
        $gateway = $this->gateway();

        $this->assertInstanceOf(DisablesSsr::class, $gateway);
        $this->assertInstanceOf(ExcludesSsrPaths::class, $gateway);
        $this->assertInstanceOf(ConfiguresSsrRequests::class, $gateway);
    }

    public function testATrueConditionSwitchesSsrOffEvenWhenTheConfigEnablesIt(): void
    {
        $this->config->ssrEnabled = true;
        $gateway                  = $this->httpGateway();
        $gateway->disable(true);

        $this->dispatchFor($gateway, 'users');

        $this->assertSame(0, $this->sent);
    }

    public function testAFalseConditionKeepsSsrOnEvenWhenTheConfigDisablesIt(): void
    {
        $this->config->ssrEnabled = false;
        $gateway                  = $this->httpGateway();
        $gateway->disable(false);

        $this->dispatchFor($gateway, 'users');

        $this->assertSame(1, $this->sent);
    }

    public function testAClosureConditionRunsAtDispatchTime(): void
    {
        /** @var ArrayObject<int, int> $calls */
        $calls = new ArrayObject();
        /** @var ArrayObject<int, bool> $off */
        $off     = new ArrayObject([true]);
        $gateway = $this->httpGateway();
        $gateway->disable(static function () use ($calls, $off): bool {
            $calls->append(1);

            return $off[0];
        });

        $this->assertCount(0, $calls);

        $this->dispatchFor($gateway, 'users');
        $this->assertSame(0, $this->sent);
        $this->assertCount(1, $calls);

        $off[0] = false;
        $this->dispatchFor($gateway, 'users');
        $this->assertSame(1, $this->sent);
    }

    public function testTheConfigFlagDecidesWhenNoConditionIsSet(): void
    {
        $this->config->ssrEnabled = false;
        $this->dispatchFor($this->httpGateway(), 'users');
        $this->assertSame(0, $this->sent);

        $this->config->ssrEnabled = true;
        $this->dispatchFor($this->httpGateway(), 'users');
        $this->assertSame(1, $this->sent);
    }

    public function testAnExcludedPathSkipsSsrOnTopOfAFalseCondition(): void
    {
        $gateway = $this->httpGateway();
        $gateway->except('admin/*');
        $gateway->disable(false);

        $this->dispatchFor($gateway, 'admin/users');

        $this->assertSame(0, $this->sent);
    }

    /**
     * @param list<string>|string $pattern
     */
    #[DataProvider('provideExcludedPathsSkipSsr')]
    public function testExcludedPathsSkipSsr(array|string $pattern, string $route, bool $excluded): void
    {
        $gateway = $this->httpGateway();
        $gateway->except($pattern);

        $this->dispatchFor($gateway, $route);

        $this->assertSame($excluded ? 0 : 1, $this->sent);
    }

    /**
     * @return iterable<string, array{list<string>|string, string, bool}>
     */
    public static function provideExcludedPathsSkipSsr(): iterable
    {
        yield 'wildcard matches a nested path' => ['admin/*', 'admin/users', true];

        yield 'wildcard is anchored at the start' => ['admin/*', 'administrator', false];

        yield 'matching is case sensitive' => ['admin/*', 'Admin/users', false];

        yield 'slashes around a pattern are trimmed' => ['/dashboard/', 'dashboard', true];

        yield 'a trailing slash on the request path is ignored' => ['dashboard', 'dashboard/', true];

        yield 'a slash pattern is the root' => ['/', '/', true];

        yield 'a slash pattern is not another path' => ['/', 'users', false];

        yield 'an exact pattern does not match a longer path' => ['dash', 'dashboard', false];

        yield 'a regex character is literal' => ['a.c', 'abc', false];

        yield 'a wildcard crosses a decoded newline' => ['admin/*', 'admin/a%0Ab', true];

        yield 'a full url pattern matches with its query' => ['http://example.com/reports?page=2', 'reports?page=2', true];

        yield 'a full url pattern with a query does not match a request without it' => ['http://example.com/reports?page=2', 'reports', false];

        yield 'a full url pattern without a query does not match a request with one' => ['http://example.com/reports', 'reports?page=2', false];

        yield 'a query is sorted before matching' => ['http://example.com/r?a=1&b=2', 'r?b=2&a=1', true];

        yield 'a full url pattern ignores a trailing slash on the request' => ['http://example.com/dashboard', 'dashboard/', true];

        yield 'a full url pattern without a path matches the root' => ['http://example.com', '/', true];

        yield 'a root query keeps the slash before it' => ['http://example.com/?x=1', '?x=1', true];

        yield 'a full url pattern for another host does not match' => ['http://other.test/reports*', 'reports', false];

        yield 'a percent encoded path matches the decoded pattern' => ['café', 'caf%C3%A9', true];

        yield 'a list of patterns' => [['a', 'b'], 'b', true];
    }

    public function testExcludedPathsAccumulateAcrossCalls(): void
    {
        $gateway = $this->httpGateway();
        $gateway->except(['a', 'b']);
        $gateway->except('c');

        foreach (['a', 'b', 'c'] as $route) {
            $this->dispatchFor($gateway, $route);
        }

        $this->assertSame(0, $this->sent);

        $this->dispatchFor($gateway, 'd');
        $this->assertSame(1, $this->sent);
    }

    public function testTheRoutePathIsMatchedUnderASubfolderBaseUrl(): void
    {
        $gateway = $this->httpGateway();
        $gateway->except('admin/*');

        $this->dispatchFor($gateway, 'admin/users', 'http://example.com/app/');
        $this->assertSame(0, $this->sent);

        $other = $this->httpGateway();
        $other->except('app/*');
        $this->dispatchFor($other, 'admin/users', 'http://example.com/app/');
        $this->assertSame(1, $this->sent);
    }

    public function testAFullUrlPatternCarriesTheSubfolderBaseUrl(): void
    {
        $gateway = $this->httpGateway();
        $gateway->except('http://example.com/app/admin*');

        $this->dispatchFor($gateway, 'admin/users', 'http://example.com/app/');

        $this->assertSame(0, $this->sent);
    }

    /**
     * @param array{string, string, string, ?string, string} $request base URL, route, index page, REQUEST_URI
     */
    #[DataProvider('provideAFullUrlPatternMatchesTheUrlTheClientRequested')]
    public function testAFullUrlPatternMatchesTheUrlTheClientRequested(string $pattern, array $request, bool $excluded): void
    {
        $gateway = $this->httpGateway();
        $gateway->except($pattern);

        [$baseUrl, $route, $indexPage, $requestUri] = $request;
        $this->dispatchFor($gateway, $route, $baseUrl, $requestUri, $indexPage);

        $this->assertSame($excluded ? 0 : 1, $this->sent);
    }

    /**
     * @return iterable<string, array{string, array{string, string, string, ?string}, bool}>
     */
    public static function provideAFullUrlPatternMatchesTheUrlTheClientRequested(): iterable
    {
        $base = 'http://example.com/';

        yield 'a rewritten request has no index page' => ['http://example.com/reports*', [$base, 'reports', 'index.php', '/reports?page=2'], true];

        yield 'a request through the index page does' => ['http://example.com/reports*', [$base, 'reports', 'index.php', '/index.php/reports'], false];

        yield 'a pattern naming the index page matches it' => ['http://example.com/index.php/reports*', [$base, 'reports', 'index.php', '/index.php/reports'], true];

        yield 'a subfolder is part of the url' => ['http://example.com/app/admin/*', ['http://example.com/app/', 'admin/users', '', '/app/admin/users'], true];

        yield 'a path pattern still matches the route path under a subfolder' => ['admin/*', ['http://example.com/app/', 'admin/users', '', '/app/admin/users'], true];

        yield 'an absolute form request uri is reduced to its path' => ['http://example.com/reports', [$base, 'reports', '', 'http://example.com/reports'], true];

        yield 'a dot in a query key is kept' => ['http://example.com/r?a.b=1', [$base, 'r', '', '/r?a.b=1'], true];

        yield 'a plus in a query value is rebuilt as an encoded space' => ['http://example.com/r?q=a%20b', [$base, 'r', '', '/r?q=a+b'], true];

        yield 'a raw nul ends a query pair' => ['http://example.com/r?a=1', [$base, 'r', '', "/r?a=1\0x"], true];

        yield 'an encoded nul ends a query key' => ['http://example.com/r?a=1', [$base, 'r', '', '/r?a%00x=1'], true];

        yield 'leading spaces of a query key are dropped' => ['http://example.com/r?a=1', [$base, 'r', '', '/r?%20%20a=1'], true];

        yield 'a numeric looking key hex is kept as a string key' => ['http://example.com/r?e=1', [$base, 'r', '', '/r?e=1'], true];

        yield 'a bracketed key keeps its brackets' => ['http://example.com/r?a%5B0%5D=1', [$base, 'r', '', '/r?a[0]=1'], true];

        yield 'a question mark inside the query is kept' => ['http://example.com/r?a=b%3Fc', [$base, 'r', '', '/r?a=b?c'], true];

        yield 'the query comes from the request uri' => ['http://example.com/reports?page=2', [$base, 'reports', '', '/reports?page=2'], true];
    }

    public function testASubclassDecidesWhetherSsrIsEnabledForTheRequest(): void
    {
        $gateway = new class ($this->config, fn (array $options): CURLRequest => $this->makeClient($options), $this->detector()) extends HttpGateway {
            public ?IncomingRequest $seen = null;

            protected function ssrIsEnabled(IncomingRequest $request): bool
            {
                $this->seen = $request;

                return true;
            }
        };
        $gateway->except('admin/*');
        $request = $this->requestFor('admin/users');
        $this->answer('200 OK', '{"body":"b"}');

        $gateway->dispatch($this->page(), $request);

        $this->assertSame($request, $gateway->seen);
        $this->assertSame(1, $this->sent);
    }

    public function testASubclassDecidesWhichRequestsAreExcluded(): void
    {
        $gateway = new class ($this->config, fn (array $options): CURLRequest => $this->makeClient($options), $this->detector()) extends HttpGateway {
            protected function inExceptArray(IncomingRequest $request): bool
            {
                return str_starts_with($request->getPath(), 'private');
            }
        };
        $gateway->except('public/*');

        $this->dispatchFor($gateway, 'private/area');
        $this->assertSame(0, $this->sent);

        $this->dispatchFor($gateway, 'public/area');
        $this->assertSame(1, $this->sent);
    }

    public function testTheCurrentRequestIsUsedWhenNoneIsPassed(): void
    {
        Services::injectMock('request', $this->requestFor('admin/users'));
        $gateway = $this->httpGateway();
        $gateway->except('admin/*');
        $this->answer('200 OK', '{"body":"b"}');

        $gateway->dispatch($this->page());

        $this->assertSame(0, $this->sent);
    }

    public function testAConfiguratorAddsAHeaderToTheSentRequest(): void
    {
        $gateway = $this->httpGateway();
        $gateway->configureRequestUsing(static fn (CURLRequest $client): CURLRequest => $client->setHeader('X-Trace', 'abc'));

        $this->dispatchFor($gateway, 'users');

        $this->assertContains('X-Trace: abc', $this->client->curl_options[CURLOPT_HTTPHEADER]);
    }

    public function testAConfiguratorReturningNothingKeepsTheOriginalClient(): void
    {
        $gateway = $this->httpGateway();
        $gateway->configureRequestUsing(static function (CURLRequest $client): ?CURLRequest {
            $client->setHeader('X-Trace', 'abc');

            return null;
        });

        $this->dispatchFor($gateway, 'users');

        $this->assertSame(1, $this->sent);
        $this->assertContains('X-Trace: abc', $this->client->curl_options[CURLOPT_HTTPHEADER]);
    }

    public function testAConfiguratorCanReturnAnotherClient(): void
    {
        $other   = null;
        $gateway = $this->httpGateway();
        $gateway->configureRequestUsing(function () use (&$other): CURLRequest {
            $other = $this->makeClient([])->setHeader('X-Other', '1');

            return $other;
        });

        $this->dispatchFor($gateway, 'users');

        $this->assertSame($other, $this->client);
        $this->assertContains('X-Other: 1', $this->client->curl_options[CURLOPT_HTTPHEADER]);
    }

    public function testClearingTheConfiguratorRestoresThePlainClient(): void
    {
        $gateway = $this->httpGateway();
        $gateway->configureRequestUsing(static fn (CURLRequest $client): CURLRequest => $client->setHeader('X-Trace', 'abc'));
        $gateway->configureRequestUsing();

        $this->dispatchFor($gateway, 'users');

        $this->assertNotContains('X-Trace: abc', $this->client->curl_options[CURLOPT_HTTPHEADER]);
    }

    public function testTheConfiguratorRunsOncePerDispatch(): void
    {
        /** @var ArrayObject<int, int> $calls */
        $calls   = new ArrayObject();
        $gateway = $this->httpGateway();
        $gateway->configureRequestUsing(static function () use ($calls): ?CURLRequest {
            $calls->append(1);

            return null;
        });

        $this->dispatchFor($gateway, 'users');
        $this->assertCount(1, $calls);

        $this->dispatchFor($gateway, 'users');
        $this->assertCount(2, $calls);
    }
}
