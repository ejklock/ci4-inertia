<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App as AppConfig;
use Config\Services;
use Ejklock\Ci4Inertia\Inertia;
use Ejklock\Ci4Inertia\InertiaResponse;
use Ejklock\Ci4Inertia\ProvidesInertiaProperties;
use Ejklock\Ci4Inertia\RenderContext;
use Ejklock\Ci4Inertia\ResponseFactory;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Verifies the static `Inertia` class reaches the shared `inertia` service on every call,
 * answers what the factory answers, and keeps the same public surface as `ResponseFactory`.
 *
 * @internal
 */
final class InertiaTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Services::reset(true);

        parent::tearDown();
    }

    private function makeRequest(array $headers = []): IncomingRequest
    {
        $config  = config(AppConfig::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'users'), null, new UserAgent());

        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }

        return $request;
    }

    private function injectFactory(array $headers = ['X-Inertia' => 'true'], string $version = 'v1'): ResponseFactory
    {
        $factory = new ResponseFactory($this->makeRequest($headers), 'app', static fn (): string => $version);

        Services::injectMock('inertia', $factory);

        return $factory;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageOf(InertiaResponse $response): array
    {
        $result = $response->toResponse($this->makeRequest(['X-Inertia' => 'true']), service('response', null, false));

        return json_decode((string) $result->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testRenderCarriesSharedAndPerRenderProps(): void
    {
        $this->injectFactory();

        Inertia::share('a', 'shared');
        $page = $this->pageOf(Inertia::render('C', ['b' => 2]));

        $this->assertSame('C', $page['component']);
        $this->assertSame(['a' => 'shared', 'b' => 2], $page['props']);
    }

    public function testShareTakesADotKeyAndAPropertiesProvider(): void
    {
        $this->injectFactory();

        Inertia::share('auth.user', 'Ada');
        Inertia::share(new class () implements ProvidesInertiaProperties {
            public function toInertiaProperties(RenderContext $context): iterable
            {
                return ['locale' => 'pt'];
            }
        });
        $page = $this->pageOf(Inertia::render('C'));

        $this->assertSame(['auth' => ['user' => 'Ada'], 'locale' => 'pt'], $page['props']);
        $this->assertSame(['auth', 'locale'], $page['sharedProps']);
    }

    public function testDeferReturnsADeferPropInTheGivenGroup(): void
    {
        $this->injectFactory();

        $prop = Inertia::defer(static fn (): int => 5, 'side');

        $this->assertSame('side', $prop->group());
    }

    public function testMergeAndDeepMergePropsAreListedByTheRender(): void
    {
        $this->injectFactory();

        $merge = Inertia::merge(['x']);
        $deep  = Inertia::deepMerge(['y']);
        $page  = $this->pageOf(Inertia::render('C', ['m' => $merge, 'd' => $deep]));

        $this->assertSame(['m'], $page['mergeProps']);
        $this->assertSame(['d'], $page['deepMergeProps']);
    }

    public function testVersionSetsThePageVersion(): void
    {
        $this->injectFactory();

        Inertia::version('v9');

        $this->assertSame('v9', $this->pageOf(Inertia::render('C'))['version']);
    }

    public function testLocationAnswers409OnAnInertiaRequest(): void
    {
        $this->injectFactory();

        $response = Inertia::location('/x');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('/x', $response->getHeaderLine('X-Inertia-Location'));
    }

    public function testEncryptHistoryAndClearHistoryShowInThePage(): void
    {
        $this->mockSession();
        $this->injectFactory();

        Inertia::encryptHistory();
        Inertia::clearHistory();
        $page = $this->pageOf(Inertia::render('C'));

        $this->assertTrue($page['encryptHistory']);
        $this->assertTrue($page['clearHistory']);
    }

    public function testCallsReachTheFactoryInjectedAfterAReset(): void
    {
        $this->injectFactory(version: 'first');
        $this->assertSame('first', $this->pageOf(Inertia::render('C'))['version']);

        Services::reset(true);
        $this->injectFactory(version: 'second');

        $this->assertSame('second', $this->pageOf(Inertia::render('C'))['version']);
    }

    public function testTheClassCannotBeInstantiated(): void
    {
        $constructor = (new ReflectionClass(Inertia::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    public function testStaticSurfaceMatchesTheFactoryPublicMethods(): void
    {
        $this->assertSurfaceMatches(ResponseFactory::class, Inertia::class);
    }

    public function testSurfaceCheckNamesAMethodTheStaticClassLacks(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('extraMethod');

        $this->assertSurfaceMatches(FactoryWithExtraMethod::class, Inertia::class);
    }

    /**
     * @param class-string $factory
     * @param class-string $static
     */
    private function assertSurfaceMatches(string $factory, string $static): void
    {
        $expected = $this->methodsByName(
            array_filter(
                (new ReflectionClass($factory))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => ! $method->isConstructor(),
            ),
        );
        $actual = $this->methodsByName(
            array_filter(
                (new ReflectionClass($static))->getMethods(),
                static fn (ReflectionMethod $method): bool => $method->isPublic() && $method->isStatic(),
            ),
        );

        foreach (array_keys($expected) as $name) {
            $this->assertArrayHasKey($name, $actual, "{$static} lacks the static method {$name}()");
        }

        foreach (array_keys($actual) as $name) {
            $this->assertArrayHasKey($name, $expected, "{$static}::{$name}() has no counterpart in {$factory}");
        }

        foreach ($expected as $name => $method) {
            $this->assertSame($this->signatureOf($method), $this->signatureOf($actual[$name]), "{$name}() signature differs");
        }
    }

    /**
     * @param iterable<ReflectionMethod> $methods
     *
     * @return array<string, ReflectionMethod>
     */
    private function methodsByName(iterable $methods): array
    {
        $byName = [];

        foreach ($methods as $method) {
            $byName[$method->getName()] = $method;
        }

        return $byName;
    }

    /**
     * @return array{params: list<array{string, string, bool, string, bool}>, return: string}
     */
    private function signatureOf(ReflectionMethod $method): array
    {
        return [
            'params' => array_map(
                static fn (ReflectionParameter $parameter): array => [
                    $parameter->getName(),
                    (string) $parameter->getType(),
                    $parameter->isDefaultValueAvailable(),
                    $parameter->isDefaultValueAvailable() ? var_export($parameter->getDefaultValue(), true) : '',
                    $parameter->isVariadic(),
                ],
                $method->getParameters(),
            ),
            'return' => (string) $method->getReturnType(),
        ];
    }
}

interface FactoryWithExtraMethod
{
    public function render(string $component, array $props = []): InertiaResponse;

    public function extraMethod(): void;
}
