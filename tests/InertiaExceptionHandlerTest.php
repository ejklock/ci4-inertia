<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use Closure;
use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use CodeIgniter\View\View;
use Config\App as AppConfig;
use Config\Services;
use Ejklock\Ci4Inertia\ExceptionResponse;
use Ejklock\Ci4Inertia\Filters\InertiaFilter;
use Ejklock\Ci4Inertia\Inertia;
use Ejklock\Ci4Inertia\InertiaExceptionHandler;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Verifies the exception handler answers an uncaught exception with an Inertia page when
 * the application's callback asks for one, and leaves everything else to CodeIgniter's own
 * handler.
 *
 * @internal
 */
final class InertiaExceptionHandlerTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    private const CONFIG_VERSION = 'config-version';

    private string $originalRootView;
    private Closure $originalVersion;

    /**
     * @var array<string, mixed>
     */
    private array $originalAliases;

    private ?string $viewDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();

        $inertia                = config('Inertia');
        $this->originalRootView = $inertia->rootView;
        $this->originalVersion  = $inertia->version;
        $inertia->rootView      = 'Ejklock\Ci4InertiaTestSupport\Views\shell';
        $inertia->version       = static fn (): string => self::CONFIG_VERSION;

        $filters               = config('Filters');
        $this->originalAliases = $filters->aliases;

        ExceptionTestFilter::$versionCalls = 0;
        Services::reset(true);
    }

    protected function tearDown(): void
    {
        InertiaExceptionHandler::respondUsing(null);

        $inertia           = config('Inertia');
        $inertia->rootView = $this->originalRootView;
        $inertia->version  = $this->originalVersion;

        config('Filters')->aliases = $this->originalAliases;

        if ($this->viewDirectory !== null) {
            array_map(unlink(...), glob($this->viewDirectory . '/*') ?: []);
            rmdir($this->viewDirectory);
            $this->viewDirectory = null;
        }

        Services::reset(true);

        parent::tearDown();
    }

    /**
     * @param array<string, string> $headers
     */
    private function makeRequest(array $headers = []): IncomingRequest
    {
        $config  = config(AppConfig::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'users'), null, new UserAgent());

        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }

        return $request;
    }

    private function makeResponse(): ResponseInterface
    {
        return Services::response(null, false);
    }

    private function exception(): Throwable
    {
        return new RuntimeException('Something broke.');
    }

    /**
     * Runs a handler and returns what it left on the response and what it sent.
     *
     * @return array{0: string, 1: ResponseInterface}
     */
    private function sentBy(callable $handle, ResponseInterface $response): array
    {
        $level = ob_get_level();
        ob_start();

        try {
            $handle();
        } finally {
            $output = '';

            while (ob_get_level() > $level) {
                $output = ob_get_clean() . $output;
            }
        }

        return [$output, $response];
    }

    /**
     * @return array{0: string, 1: ResponseInterface}
     */
    private function handledByThePackage(Throwable $exception, CLIRequest|IncomingRequest $request, int $status = 500, ?ResponseInterface $response = null): array
    {
        $response ??= $this->makeResponse();

        return $this->sentBy(
            static fn () => (new InertiaExceptionHandler(config('Exceptions')))->handle($exception, $request, $response, $status, 1),
            $response,
        );
    }

    /**
     * @return array{0: string, 1: ResponseInterface}
     */
    private function handledByCodeIgniter(Throwable $exception, CLIRequest|IncomingRequest $request, int $status = 500): array
    {
        $response = $this->makeResponse();

        return $this->sentBy(
            static fn () => (new ExceptionHandler(config('Exceptions')))->handle($exception, $request, $response, $status, 1),
            $response,
        );
    }

    /**
     * @return array<string, string>
     */
    private function headersOf(ResponseInterface $response): array
    {
        $headers = [];

        foreach ($response->headers() as $name => $header) {
            $headers[strtolower((string) $name)] = $response->getHeaderLine((string) $name);
        }

        ksort($headers);

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageOf(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function registerErrorPage(): void
    {
        Inertia::handleExceptionsUsing(
            static fn (ExceptionResponse $r) => $r->render('Errors/Show', ['status' => $r->statusCode()]),
        );
    }

    private function useFilterAlias(string $alias, array|string $value): void
    {
        config('Filters')->aliases[$alias] = $value;
    }

    public function testWithoutACallbackCodeIgniterAnswersAsItDoes(): void
    {
        foreach (['application/json', 'text/html'] as $accept) {
            $request = $this->makeRequest(['Accept' => $accept]);
            $error   = $this->exception();

            [$expectedOutput, $expected] = $this->handledByCodeIgniter($error, $request, 503);
            [$output, $response]         = $this->handledByThePackage($error, $request, 503);

            $this->assertSame($expected->getStatusCode(), $response->getStatusCode(), $accept);
            $this->assertSame($this->headersOf($expected), $this->headersOf($response), $accept);

            if ($accept === 'application/json') {
                $this->assertSame($expectedOutput, $output, $accept);

                continue;
            }

            // CodeIgniter's HTML error page embeds per-run element ids, so only its content is compared.
            $this->assertStringContainsString('Something broke.', $output);
            $this->assertStringNotContainsString('data-page', $output);
        }
    }

    public function testACallbackReturningNullLeavesTheAnswerToCodeIgniter(): void
    {
        Inertia::handleExceptionsUsing(static fn (ExceptionResponse $r) => null);

        $request = $this->makeRequest(['Accept' => 'application/json']);
        $error   = $this->exception();

        [$expectedOutput, $expected] = $this->handledByCodeIgniter($error, $request, 404);
        [$output, $response]         = $this->handledByThePackage($error, $request, 404);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($this->headersOf($expected), $this->headersOf($response));
        $this->assertSame($expectedOutput, $output);
    }

    #[DataProvider('provideStatusCodes')]
    public function testAnInertiaRequestGetsTheJsonPageWithTheHandlersStatus(int $status): void
    {
        $this->registerErrorPage();

        [$output, $response] = $this->handledByThePackage($this->exception(), $this->makeRequest(['X-Inertia' => 'true']), $status);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('true', $response->getHeaderLine('X-Inertia'));
        $this->assertStringContainsString('X-Inertia', $response->getHeaderLine('Vary'));

        $page = $this->pageOf($response);

        $this->assertSame('Errors/Show', $page['component']);
        $this->assertSame(['status' => $status], $page['props']);
        $this->assertSame($output, (string) $response->getBody());
    }

    #[DataProvider('provideStatusCodes')]
    public function testABrowserRequestGetsTheHtmlShellWithTheHandlersStatus(int $status): void
    {
        $this->registerErrorPage();

        [$output, $response] = $this->handledByThePackage($this->exception(), $this->makeRequest(['Accept' => 'text/html']), $status);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('X-Inertia'));
        $this->assertStringContainsString('X-Inertia', $response->getHeaderLine('Vary'));
        $this->assertStringContainsString('Inertia test shell', $output);
        $this->assertStringContainsString('"component":"Errors/Show","props":{"status":' . $status . '}', $output);
        $this->assertSame($output, (string) $response->getBody());
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function provideStatusCodes(): iterable
    {
        yield 'not found' => [404];

        yield 'server error' => [500];

        yield 'service unavailable' => [503];
    }

    public function testAnExceptionResponseThatRenderedNothingSendsTheGivenResponseWithTheHandlersStatus(): void
    {
        Inertia::handleExceptionsUsing(static fn (ExceptionResponse $r) => $r);

        $response = $this->makeResponse()->setHeader('X-Marker', 'kept')->setBody('untouched');

        [$output, $sent] = $this->handledByThePackage($this->exception(), $this->makeRequest(['X-Inertia' => 'true']), 503, $response);

        $this->assertSame($response, $sent);
        $this->assertSame(503, $sent->getStatusCode());
        $this->assertSame('kept', $sent->getHeaderLine('X-Marker'));
        $this->assertSame('', $sent->getHeaderLine('X-Inertia'));
        $this->assertSame('untouched', $output);
    }

    public function testAResponseReturnedByTheCallbackIsSent(): void
    {
        $other = $this->makeResponse()->setStatusCode(418)->setBody('teapot');

        Inertia::handleExceptionsUsing(static fn (ExceptionResponse $r) => $other);

        [$output, $given] = $this->handledByThePackage($this->exception(), $this->makeRequest(), 500);

        $this->assertSame('teapot', $output);
        $this->assertSame(500, $given->getStatusCode());
    }

    public function testAnythingElseTheCallbackReturnsIsRejected(): void
    {
        InertiaExceptionHandler::respondUsing(static fn (ExceptionResponse $r) => 'oops');

        $this->expectException(LogicException::class);

        $this->handledByThePackage($this->exception(), $this->makeRequest());
    }

    public function testACliRequestGoesToCodeIgniterWithoutCallingTheCallback(): void
    {
        $called = false;

        Inertia::handleExceptionsUsing(static function (ExceptionResponse $r) use (&$called) {
            $called = true;

            return null;
        });

        $request = new CLIRequest(config(AppConfig::class));
        $error   = $this->exception();

        $this->handledByCodeIgniter($error, $request);
        $expected = $this->getStreamFilterBuffer();
        $this->resetStreamFilterBuffer();

        $this->handledByThePackage($error, $request);

        $this->assertFalse($called);
        $this->assertStringContainsString('Something broke.', $expected);
        $this->assertSame(ltrim($expected), ltrim($this->getStreamFilterBuffer()));
    }

    public function testAnInvalidStatusCodeBecomesAServerError(): void
    {
        $this->registerErrorPage();

        [, $response] = $this->handledByThePackage($this->exception(), $this->makeRequest(['X-Inertia' => 'true']), 999);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['status' => 500], $this->pageOf($response)['props']);
    }

    public function testAnUntouchedResponseCarriesAServerErrorForAnInvalidStatusCode(): void
    {
        Inertia::handleExceptionsUsing(static fn (ExceptionResponse $r) => $r);

        [, $response] = $this->handledByThePackage($this->exception(), $this->makeRequest(), 999);

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testTheCallbackReceivesWhatTheHandlerGot(): void
    {
        $seen    = null;
        $request = $this->makeRequest();
        $error   = $this->exception();
        $given   = $this->makeResponse();

        Inertia::handleExceptionsUsing(static function (ExceptionResponse $r) use (&$seen) {
            $seen = $r;

            return $r;
        });

        $this->handledByThePackage($error, $request, 404, $given);

        $this->assertInstanceOf(ExceptionResponse::class, $seen);
        $this->assertSame($error, $seen->exception);
        $this->assertSame($request, $seen->request);
        $this->assertSame($given, $seen->response);
        $this->assertSame(404, $seen->statusCode());
        $this->assertTrue((new ReflectionProperty($seen, 'exception'))->isReadOnly());
        $this->assertTrue((new ReflectionProperty($seen, 'request'))->isReadOnly());
        $this->assertTrue((new ReflectionProperty($seen, 'response'))->isReadOnly());
    }

    public function testASecondRegistrationReplacesTheFirst(): void
    {
        Inertia::handleExceptionsUsing(static fn (ExceptionResponse $r) => $r->render('First'));
        Inertia::handleExceptionsUsing(static fn (ExceptionResponse $r) => $r->render('Second'));

        [, $response] = $this->handledByThePackage($this->exception(), $this->makeRequest(['X-Inertia' => 'true']));

        $this->assertSame('Second', $this->pageOf($response)['component']);
    }

    public function testClearingTheCallbackLeavesTheAnswerToCodeIgniter(): void
    {
        $called = false;

        Inertia::handleExceptionsUsing(static function (ExceptionResponse $r) use (&$called) {
            $called = true;

            return $r->render('Errors/Show');
        });
        InertiaExceptionHandler::respondUsing(null);

        $request = $this->makeRequest(['Accept' => 'application/json']);
        $error   = $this->exception();

        [$expectedOutput] = $this->handledByCodeIgniter($error, $request);
        [$output]         = $this->handledByThePackage($error, $request);

        $this->assertFalse($called);
        $this->assertSame($expectedOutput, $output);
    }

    private function renderWithSharedData(bool $withSharedData, ?string $usingFilter = null): array
    {
        Inertia::handleExceptionsUsing(static function (ExceptionResponse $r) use ($withSharedData, $usingFilter) {
            if ($usingFilter !== null) {
                $r->usingFilter($usingFilter);
            }

            if ($withSharedData) {
                $r->withSharedData();
            }

            return $r->render('Errors/Show');
        });

        [, $response] = $this->handledByThePackage($this->exception(), $this->makeRequest(['X-Inertia' => 'true']), 500);

        return $this->pageOf($response);
    }

    public function testThePageUsesTheVersionOfTheFilterRegisteredAsAnAlias(): void
    {
        $this->useFilterAlias('inertia', ExceptionTestFilter::class);

        $page = $this->renderWithSharedData(false);

        $this->assertSame('filter-version', $page['version']);
        $this->assertArrayNotHasKey('flavor', $page['props']);
        $this->assertArrayNotHasKey('errors', $page['props']);
    }

    public function testSharedDataBringsTheFiltersSharedPropsIncludingErrors(): void
    {
        $this->useFilterAlias('inertia', ExceptionTestFilter::class);

        $page = $this->renderWithSharedData(true);

        $this->assertSame('filter-version', $page['version']);
        $this->assertSame('vanilla', $page['props']['flavor']);
        $this->assertSame([], $page['props']['errors']);
    }

    public function testTheFilterNamedByTheCallbackBeatsTheAliases(): void
    {
        $this->useFilterAlias('first', OtherExceptionTestFilter::class);
        $this->useFilterAlias('second', ExceptionTestFilter::class);

        $page = $this->renderWithSharedData(true, ExceptionTestFilter::class);

        $this->assertSame('filter-version', $page['version']);
        $this->assertSame('vanilla', $page['props']['flavor']);
    }

    public function testTheFirstInertiaFilterAmongTheAliasesIsUsed(): void
    {
        config('Filters')->aliases = ['csrf' => stdClass::class, 'first' => OtherExceptionTestFilter::class, 'second' => ExceptionTestFilter::class];

        $this->assertSame('other-version', $this->renderWithSharedData(false)['version']);
    }

    public function testAnAliasHoldingAListIsSearchedToo(): void
    {
        config('Filters')->aliases = ['group' => [stdClass::class, ExceptionTestFilter::class]];

        $this->assertSame('filter-version', $this->renderWithSharedData(false)['version']);
    }

    public function testWithoutAnInertiaFilterThePageUsesTheConfigVersionAndSharesNothing(): void
    {
        config('Filters')->aliases = ['csrf' => stdClass::class];

        $page = $this->renderWithSharedData(true);

        $this->assertSame(self::CONFIG_VERSION, $page['version']);
        $this->assertSame([], $page['props']);
    }

    public function testTheFiltersVersionIsResolvedOncePerRender(): void
    {
        $this->useFilterAlias('inertia', ExceptionTestFilter::class);

        $this->renderWithSharedData(true);

        $this->assertSame(1, ExceptionTestFilter::$versionCalls);
    }

    public function testAFilterThatIsNotAnInertiaFilterIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderWithSharedData(false, stdClass::class);
    }

    public function testTheRootViewNamedByTheCallbackRendersTheHtmlShell(): void
    {
        $this->viewDirectory = sys_get_temp_dir() . '/ci4-inertia-views-' . bin2hex(random_bytes(4));
        mkdir($this->viewDirectory);
        file_put_contents($this->viewDirectory . '/errors_shell.php', '<p>errors shell <?= esc($page["component"]) ?></p>');
        Services::injectMock('renderer', new View(config('View'), $this->viewDirectory . '/'));

        Inertia::handleExceptionsUsing(static fn (ExceptionResponse $r) => $r->rootView('errors_shell')->render('Errors/Show'));

        [$output] = $this->handledByThePackage($this->exception(), $this->makeRequest(['Accept' => 'text/html']), 500);

        $this->assertStringContainsString('<p>errors shell Errors/Show</p>', $output);
        $this->assertStringNotContainsString('Inertia test shell', $output);
    }

    public function testWithoutARootViewTheConfiguredOneRenders(): void
    {
        $this->registerErrorPage();

        [$output] = $this->handledByThePackage($this->exception(), $this->makeRequest(['Accept' => 'text/html']), 500);

        $this->assertStringContainsString('Inertia test shell', $output);
    }
}

/**
 * @internal
 */
final class ExceptionTestFilter extends InertiaFilter
{
    public static int $versionCalls = 0;

    protected function version(IncomingRequest $request): string
    {
        self::$versionCalls++;

        return 'filter-version';
    }

    protected function share(IncomingRequest $request): array
    {
        return parent::share($request) + ['flavor' => 'vanilla'];
    }
}

/**
 * @internal
 */
final class OtherExceptionTestFilter extends InertiaFilter
{
    protected function version(IncomingRequest $request): string
    {
        return 'other-version';
    }
}
