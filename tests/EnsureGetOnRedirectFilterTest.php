<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use CodeIgniter\Config\BaseService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Ejklock\Ci4Inertia\Filters\EnsureGetOnRedirectFilter;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies `Ejklock\Ci4Inertia\Filters\EnsureGetOnRedirectFilter` on its own, without the
 * main Inertia filter: only an Inertia `PUT`, `PATCH` or `DELETE` answered with a `302`
 * is rewritten to `303`.
 *
 * @internal
 */
final class EnsureGetOnRedirectFilterTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    /**
     * @var array<string, string>
     */
    private array $originalAliases;

    protected function setUp(): void
    {
        parent::setUp();

        $filters                                 = config('Filters');
        $this->originalAliases                   = $filters->aliases;
        $filters->aliases['ensureGetOnRedirect'] = EnsureGetOnRedirectFilter::class;

        $this->resetPerRequestServices();
    }

    protected function tearDown(): void
    {
        config('Filters')->aliases = $this->originalAliases;

        $this->resetPerRequestServices();

        parent::tearDown();
    }

    private function resetPerRequestServices(): void
    {
        BaseService::resetSingle('response');
        BaseService::resetSingle('routes');
        BaseService::resetSingle('router');
    }

    #[DataProvider('provideDestructiveMethods')]
    public function testInertiaDestructiveMethodRedirectIsConvertedFrom302To303(string $method): void
    {
        $this->withRoutes([
            [$method, 'page/1', static fn () => redirect()->to('/page/1', 302), ['filter' => 'ensureGetOnRedirect']],
        ]);

        $this->withHeaders(['X-Inertia' => 'true'])->call($method, 'page/1')->assertStatus(303);
    }

    #[DataProvider('provideDestructiveMethods')]
    public function testNonInertiaDestructiveMethodRedirectStaysAt302(string $method): void
    {
        $this->withRoutes([
            [$method, 'page/1', static fn () => redirect()->to('/page/1', 302), ['filter' => 'ensureGetOnRedirect']],
        ]);

        $this->call($method, 'page/1')->assertStatus(302);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideDestructiveMethods(): iterable
    {
        yield 'PUT' => ['PUT'];

        yield 'PATCH' => ['PATCH'];

        yield 'DELETE' => ['DELETE'];
    }

    public function testInertiaPostRedirectStaysAt302(): void
    {
        $this->withRoutes([
            ['POST', 'page/1', static fn () => redirect()->to('/page/1', 302), ['filter' => 'ensureGetOnRedirect']],
        ]);

        $this->withHeaders(['X-Inertia' => 'true'])->post('page/1')->assertStatus(302);
    }

    #[DataProvider('provideInertiaPutRedirectOtherThan302IsUnchanged')]
    public function testInertiaPutRedirectOtherThan302IsUnchanged(int $status): void
    {
        $this->withRoutes([
            ['PUT', 'page/1', static fn () => redirect()->to('/page/1', $status), ['filter' => 'ensureGetOnRedirect']],
        ]);

        $this->withHeaders(['X-Inertia' => 'true'])->call('PUT', 'page/1')->assertStatus($status);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function provideInertiaPutRedirectOtherThan302IsUnchanged(): iterable
    {
        yield '301' => [301];

        yield '307' => [307];
    }
}
